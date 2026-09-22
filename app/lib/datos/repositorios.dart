import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../dominio/enums.dart';
import '../dominio/modelos.dart';
import '../nucleo/api.dart';

/// Acceso a la API, agrupado por lo que la pantalla necesita.
///
/// Los repositorios devuelven modelos del dominio, nunca mapas sueltos: así
/// una respuesta que cambie de forma se rompe aquí y no en cinco pantallas.
class Repositorio {
  const Repositorio(this._api);

  final ClienteApi _api;

  // ── Autenticación ────────────────────────────────────────────────────────

  Future<Usuario> ingresar(String email, String clave) async {
    final r = await _api.enviar('/auth/login', cuerpo: {
      'email': email,
      'password': clave,
    });

    await _api.sesion.guardar(r['token'] as String);

    return Usuario.desdeJson((r['usuario'] as Map).cast<String, dynamic>());
  }

  Future<Usuario> yo() async =>
      Usuario.desdeJson(await _api.obtener('/auth/yo'));

  Future<void> salir() async {
    try {
      await _api.enviar('/auth/logout');
    } on DioException {
      // Si el token ya no vale, cerrar sesión localmente es igual de válido.
    }
    await _api.sesion.borrar();
  }

  // ── Catálogos y sedes ────────────────────────────────────────────────────

  Future<List<Sede>> sedes({String? buscar}) async {
    final r = await _api.obtener('/sedes', parametros: {
      if (buscar != null && buscar.isNotEmpty) 'buscar': buscar,
    });

    return ((r['datos'] as List?) ?? [])
        .map((e) => Sede.desdeJson((e as Map).cast<String, dynamic>()))
        .toList();
  }

  /// Registra una sede que el catálogo todavía no conoce. Vive aquí, y no
  /// solo en una pantalla de administración, porque el momento más común en
  /// que hace falta es a mitad de una carga que no pudo identificarla.
  Future<Sede> crearSede({required String codigo, required String nombre}) async {
    final r = await _api.enviar('/sedes', cuerpo: {
      'codigo': codigo,
      'nombre': nombre,
    });

    return Sede.desdeJson((r['datos'] as Map).cast<String, dynamic>());
  }

  Future<List<Estandar>> estandares() async {
    final r = await _api.obtener('/catalogos/estandares');

    return ((r['datos'] as List?) ?? [])
        .map((e) => Estandar.desdeJson((e as Map).cast<String, dynamic>()))
        .toList();
  }

  // ── Hallazgos ────────────────────────────────────────────────────────────

  Future<({List<Hallazgo> datos, int total})> hallazgos({
    int? sedeId,
    String? estandar,
    EstadoHallazgo? estado,
    String? buscar,
    bool soloVigentes = false,
    String orden = 'sede',
    int pagina = 1,
  }) async {
    final r = await _api.obtener('/hallazgos', parametros: {
      if (sedeId != null) 'sede_id': sedeId,
      if (estandar != null) 'estandar': estandar,
      if (estado != null) 'estado': estado.valor,
      if (buscar != null && buscar.isNotEmpty) 'buscar': buscar,
      // En una cadena de consulta el booleano viaja como 1/0: la regla
      // `boolean` de Laravel no acepta las palabras "true" y "false".
      if (soloVigentes) 'solo_vigentes': 1,
      'orden': orden,
      'page': pagina,
      'por_pagina': 50,
    });

    return (
      datos: ((r['data'] as List?) ?? [])
          .map((e) => Hallazgo.desdeJson((e as Map).cast<String, dynamic>()))
          .toList(),
      total: (r['total'] as int?) ?? 0,
    );
  }

  Future<Map<String, dynamic>> lineaTiempo(int hallazgoId) =>
      _api.obtener('/hallazgos/$hallazgoId/linea-tiempo');

  /// Cerrar exige evidencia: el backend lo rechaza sin ella, y con razón —
  /// un cierre sin constancia es lo que hace indefendible un consolidado.
  Future<void> cambiarEstado(
    int hallazgoId, {
    required EstadoHallazgo estado,
    String? evidencia,
    String? accionPropuesta,
    String? responsable,
  }) =>
      _api.actualizar('/hallazgos/$hallazgoId/estado', cuerpo: {
        'estado': estado.valor,
        if (evidencia != null) 'evidencia': evidencia,
        if (accionPropuesta != null) 'accion_propuesta': accionPropuesta,
        if (responsable != null) 'responsable': responsable,
      });

  // ── Autoevaluaciones ─────────────────────────────────────────────────────

  /// Sube una o varias autoevaluaciones. El servidor procesa cada archivo por
  /// separado, así que el que falle no tumba la carga de los demás: la
  /// respuesta trae las que entraron y las que no, con su motivo.
  Future<Map<String, dynamic>> cargarAuditorias(
    List<({List<int> bytes, String nombre})> archivos, {
    int? sedeId,
    String? periodo,
  }) async {
    final formulario = FormData();

    for (final a in archivos) {
      formulario.files.add(MapEntry(
        'archivos[]',
        MultipartFile.fromBytes(a.bytes, filename: a.nombre),
      ));
    }

    if (sedeId != null) formulario.fields.add(MapEntry('sede_id', '$sedeId'));
    if (periodo != null) formulario.fields.add(MapEntry('periodo', periodo));

    try {
      final r = await _api.dio.post<dynamic>('/auditorias/cargar', data: formulario);

      return (r.data as Map).cast<String, dynamic>();
    } on DioException catch (e) {
      // Un 422 con archivos rechazados no es un fallo de red: trae el detalle
      // de por qué cada archivo no se pudo leer, y la pantalla lo muestra.
      final datos = e.response?.data;

      if (e.response?.statusCode == 422 && datos is Map && datos['fallidas'] != null) {
        return datos.cast<String, dynamic>();
      }

      throw e.error is ErrorApi ? e.error! as ErrorApi : ErrorApi('$e');
    }
  }

  Future<List<Map<String, dynamic>>> auditorias({int? sedeId}) async {
    final r = await _api.obtener('/auditorias', parametros: {
      if (sedeId != null) 'sede_id': sedeId,
    });

    return ((r['datos'] as List?) ?? [])
        .map((e) => (e as Map).cast<String, dynamic>())
        .toList();
  }

  Future<Map<String, dynamic>> reconciliacionDeAuditoria(int auditoriaId) =>
      _api.obtener('/auditorias/$auditoriaId/reconciliacion');

  /// Publica la auditoría. Falla si queda alguna decisión sin tomar.
  Future<Map<String, dynamic>> confirmarAuditoria(
    int auditoriaId,
    Map<int, Map<String, dynamic>> decisiones,
  ) =>
      _api.enviar('/auditorias/$auditoriaId/confirmar', cuerpo: {
        'decisiones': decisiones.map((k, v) => MapEntry('$k', v)),
      });

  Future<void> eliminarAuditoria(int auditoriaId) async {
    try {
      await _api.dio.delete<dynamic>('/auditorias/$auditoriaId');
    } on DioException catch (e) {
      throw e.error is ErrorApi ? e.error! as ErrorApi : ErrorApi('$e');
    }
  }

  // ── Cortes mensuales ─────────────────────────────────────────────────────

  Future<List<Corte>> cortes() async {
    final r = await _api.obtener('/cortes');

    return ((r['datos'] as List?) ?? [])
        .map((e) => Corte.desdeJson((e as Map).cast<String, dynamic>()))
        .toList();
  }

  Future<Corte> corte(String periodo) async =>
      Corte.desdeJson(await _api.obtener('/cortes/$periodo'));

  Future<Corte> abrirCorte(String periodo) async {
    final r = await _api.enviar('/cortes', cuerpo: {'periodo': periodo});
    return Corte.desdeJson((r['datos'] as Map).cast<String, dynamic>());
  }

  Future<void> cerrarCorte(String periodo) =>
      _api.enviar('/cortes/$periodo/cerrar');

  Future<Map<String, dynamic>> reconciliacionDelCorte(String periodo) =>
      _api.obtener('/cortes/$periodo/reconciliacion');

  Future<void> actualizarEnCorte(
    String periodo,
    int hallazgoId, {
    required EstadoHallazgo estado,
    String? accionPropuesta,
    String? responsable,
    String? evidencia,
  }) =>
      _api.actualizar('/cortes/$periodo/hallazgos/$hallazgoId', cuerpo: {
        'estado': estado.valor,
        if (accionPropuesta != null) 'accion_propuesta': accionPropuesta,
        if (responsable != null) 'responsable': responsable,
        if (evidencia != null) 'evidencia': evidencia,
      });

  Future<List<int>> descargarMatriz(String periodo) =>
      _api.descargar('/cortes/$periodo/matriz');

  Future<Map<String, dynamic>> subirMatriz(
    String periodo,
    List<int> bytesArchivo,
    String nombre,
  ) async {
    final formulario = FormData.fromMap({
      'archivo': MultipartFile.fromBytes(bytesArchivo, filename: nombre),
    });

    try {
      final r = await _api.dio.post<dynamic>(
        '/cortes/$periodo/seguimiento',
        data: formulario,
      );

      return (r.data as Map).cast<String, dynamic>();
    } on DioException catch (e) {
      throw e.error is ErrorApi ? e.error! as ErrorApi : ErrorApi('$e');
    }
  }

  // ── Consolidado ──────────────────────────────────────────────────────────

  Future<Consolidado> consolidado({String? periodo, List<int> sedes = const []}) async {
    final r = await _api.enviar('/consolidado', cuerpo: {
      if (periodo != null) 'periodo': periodo,
      if (sedes.isNotEmpty) 'sedes': sedes,
    });

    return Consolidado.desdeJson(r);
  }

  Future<List<PuntoSerie>> serie({List<int> sedes = const []}) async {
    final r = await _api.obtener('/consolidado/serie', parametros: {
      if (sedes.isNotEmpty) 'sedes': sedes,
    });

    return ((r['datos'] as List?) ?? [])
        .map((e) => PuntoSerie.desdeJson((e as Map).cast<String, dynamic>()))
        .toList();
  }

  Future<List<int>> exportarConsolidado({
    String? periodo,
    List<int> sedes = const [],
  }) =>
      _api.descargar('/consolidado/exportar', cuerpo: {
        if (periodo != null) 'periodo': periodo,
        if (sedes.isNotEmpty) 'sedes': sedes,
      });
}

final repositorioProvider = Provider<Repositorio>(
  (ref) => Repositorio(ref.watch(apiProvider)),
);

final auditoriasProvider =
    FutureProvider.autoDispose<List<Map<String, dynamic>>>(
  (ref) => ref.watch(repositorioProvider).auditorias(),
);
