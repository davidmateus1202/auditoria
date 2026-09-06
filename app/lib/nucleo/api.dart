import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Dirección del servidor. La API vive en un servidor con conexión permanente,
/// así que la app siempre trabaja en línea: no hay base local ni sincronización.
const baseUrl = String.fromEnvironment(
  'API_URL',
  defaultValue: 'http://10.0.2.2:8000/api',
);

/// Error de API con el mensaje que se le puede mostrar a una persona.
///
/// El backend rechaza los archivos que no tienen la estructura esperada
/// indicando hoja y fila; esa información tiene que llegar entera a la
/// pantalla, no convertirse en un «error inesperado».
class ErrorApi implements Exception {
  ErrorApi(this.mensaje, {this.codigo, this.detalles});

  final String mensaje;
  final int? codigo;
  final Map<String, dynamic>? detalles;

  bool get esAutenticacion => codigo == 401;

  @override
  String toString() => mensaje;
}

/// Guarda el token en el almacén cifrado del sistema.
///
/// En algunos dispositivos Android ese almacén falla —el llavero se corrompe,
/// o el fabricante lo implementa a medias— y la escritura lanza. Eso no puede
/// impedir el ingreso: el servidor ya emitió el token y la persona ya se
/// autenticó. Se conserva en memoria y se sigue; lo único que se pierde es que
/// la sesión sobreviva a cerrar la aplicación, que es mucho menos malo que no
/// poder entrar.
class Sesion {
  Sesion(this._almacen);

  final FlutterSecureStorage _almacen;
  static const _clave = 'token_acceso';

  String? _enMemoria;

  /// Se supo que el almacén no es de fiar en este dispositivo.
  bool almacenDegradado = false;

  Future<String?> token() async {
    if (_enMemoria != null) return _enMemoria;

    try {
      return _enMemoria = await _almacen.read(key: _clave);
    } catch (_) {
      almacenDegradado = true;

      return null;
    }
  }

  Future<void> guardar(String token) async {
    _enMemoria = token;

    try {
      await _almacen.write(key: _clave, value: token);
    } catch (_) {
      almacenDegradado = true;
    }
  }

  Future<void> borrar() async {
    _enMemoria = null;

    try {
      await _almacen.delete(key: _clave);
    } catch (_) {
      almacenDegradado = true;
    }
  }
}

class ClienteApi {
  ClienteApi(this.sesion) {
    _dio = Dio(BaseOptions(
      baseUrl: baseUrl,
      connectTimeout: const Duration(seconds: 15),
      // La generación del Excel puede tardar en un consolidado grande.
      receiveTimeout: const Duration(seconds: 90),
      headers: {'Accept': 'application/json'},
    ));

    _dio.interceptors.add(InterceptorsWrapper(
      onRequest: (opciones, siguiente) async {
        final token = await sesion.token();
        if (token != null) {
          opciones.headers['Authorization'] = 'Bearer $token';
        }
        siguiente.next(opciones);
      },
      onError: (error, siguiente) => siguiente.reject(
        DioException(
          requestOptions: error.requestOptions,
          error: _traducir(error),
          type: error.type,
          response: error.response,
        ),
      ),
    ));
  }

  final Sesion sesion;
  late final Dio _dio;

  Dio get dio => _dio;

  /// Traduce el fallo a algo que explique qué pasó y qué hacer.
  ErrorApi _traducir(DioException error) {
    final respuesta = error.response;

    if (respuesta == null) {
      return ErrorApi(
        switch (error.type) {
          DioExceptionType.connectionTimeout ||
          DioExceptionType.receiveTimeout =>
            'El servidor tardó demasiado en responder. Intente de nuevo.',
          DioExceptionType.connectionError =>
            'No hay conexión con el servidor. Revise la red e intente de nuevo.',
          _ => 'No se pudo completar la operación.',
        },
      );
    }

    final datos = respuesta.data;
    final cuerpo = datos is Map<String, dynamic> ? datos : <String, dynamic>{};

    // Errores de validación de Laravel: se muestra el primero, que es el que
    // la persona tiene que corregir.
    if (respuesta.statusCode == 422 && cuerpo['errors'] is Map) {
      final errores = (cuerpo['errors'] as Map).values;
      final primero = errores.isEmpty ? null : (errores.first as List).first;
      return ErrorApi(
        '$primero' == 'null' ? 'Los datos enviados no son válidos.' : '$primero',
        codigo: 422,
        detalles: cuerpo,
      );
    }

    return ErrorApi(
      cuerpo['mensaje'] as String? ??
          cuerpo['message'] as String? ??
          switch (respuesta.statusCode) {
            401 => 'La sesión expiró. Vuelva a ingresar.',
            403 => 'No tiene permiso para hacer esto.',
            404 => 'No se encontró lo que se buscaba.',
            _ => 'El servidor respondió con un error.',
          },
      codigo: respuesta.statusCode,
      detalles: cuerpo,
    );
  }

  /// Toda petición sale por aquí para que el fallo llegue como [ErrorApi] y no
  /// envuelto en una `DioException`. Lo que ve la persona tiene que ser el
  /// mensaje del servidor —«Cerrar un hallazgo exige registrar la evidencia»—
  /// y no el nombre de la librería de red.
  Future<T> _intentar<T>(Future<T> Function() peticion) async {
    try {
      return await peticion();
    } on DioException catch (e) {
      throw e.error is ErrorApi ? e.error! as ErrorApi : _traducir(e);
    }
  }

  Future<Map<String, dynamic>> obtener(
    String ruta, {
    Map<String, dynamic>? parametros,
  }) =>
      _intentar(() async {
        final r = await _dio.get<dynamic>(ruta, queryParameters: parametros);
        return _mapa(r.data);
      });

  Future<Map<String, dynamic>> enviar(String ruta, {Object? cuerpo}) =>
      _intentar(() async {
        final r = await _dio.post<dynamic>(ruta, data: cuerpo);
        return _mapa(r.data);
      });

  Future<Map<String, dynamic>> actualizar(String ruta, {Object? cuerpo}) =>
      _intentar(() async {
        final r = await _dio.put<dynamic>(ruta, data: cuerpo);
        return _mapa(r.data);
      });

  Future<List<int>> descargar(String ruta, {Object? cuerpo}) =>
      _intentar(() async {
        final r = await _dio.request<List<int>>(
          ruta,
          data: cuerpo,
          options: Options(
            method: cuerpo == null ? 'GET' : 'POST',
            responseType: ResponseType.bytes,
          ),
        );
        return r.data ?? const [];
      });

  Map<String, dynamic> _mapa(dynamic datos) =>
      datos is Map<String, dynamic> ? datos : <String, dynamic>{'datos': datos};
}

final sesionProvider = Provider<Sesion>(
  (ref) => Sesion(const FlutterSecureStorage()),
);

final apiProvider = Provider<ClienteApi>(
  (ref) => ClienteApi(ref.watch(sesionProvider)),
);
