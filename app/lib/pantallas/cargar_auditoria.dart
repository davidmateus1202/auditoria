import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../datos/repositorios.dart';
import '../dominio/modelos.dart';
import '../nucleo/api.dart';
import '../nucleo/tema.dart';
import '../widgets/comunes.dart';
import 'auditorias.dart';
import 'confirmar_cruce.dart';
import 'inicio.dart' show consolidadoProvider;

/// Cargar autoevaluaciones: la entrada principal del sistema.
///
/// Se pueden subir varias sedes de una vez; cada archivo se procesa por
/// separado, así que el que falle no tumba a los demás. Nada queda publicado
/// aquí: la auditoría entra «por confirmar» y pasa por la pantalla del cruce.
class PantallaCargarAuditoria extends ConsumerStatefulWidget {
  const PantallaCargarAuditoria({super.key});

  @override
  ConsumerState<PantallaCargarAuditoria> createState() =>
      _PantallaCargarAuditoriaEstado();
}

class _PantallaCargarAuditoriaEstado
    extends ConsumerState<PantallaCargarAuditoria> {
  List<PlatformFile> _elegidos = [];
  bool _subiendo = false;
  Map<String, dynamic>? _resultado;
  String? _error;

  Future<void> _elegir() async {
    final seleccion = await FilePicker.pickFiles(
      type: FileType.custom,
      allowedExtensions: const ['xlsx', 'xls'],
      allowMultiple: true,
      withData: true,
    );

    if (seleccion == null) return;

    setState(() {
      _elegidos = seleccion.files.where((f) => f.bytes != null).toList();
      _resultado = null;
      _error = null;
    });
  }

  Future<void> _subir() async {
    if (_elegidos.isEmpty) return;

    setState(() {
      _subiendo = true;
      _error = null;
      _resultado = null;
    });

    try {
      final respuesta = await ref.read(repositorioProvider).cargarAuditorias(
            _elegidos.map((f) => (bytes: f.bytes!, nombre: f.name)).toList(),
          );

      if (!mounted) return;

      ref.invalidate(consolidadoProvider);
      ref.invalidate(auditoriasProvider);

      // Los que fallaron quedan seleccionados: así «Elegir sede» puede
      // reintentar ese archivo puntual sin pedir que se vuelva a adjuntar.
      final nombresCargados = ((respuesta['cargadas'] as List?) ?? const [])
          .cast<Map<String, dynamic>>()
          .map((c) => c['archivo'])
          .toSet();

      setState(() {
        _resultado = respuesta;
        _elegidos = _elegidos.where((f) => !nombresCargados.contains(f.name)).toList();
      });
    } on ErrorApi catch (e) {
      if (mounted) setState(() => _error = e.mensaje);
    } catch (e) {
      if (mounted) setState(() => _error = 'No se pudo procesar: $e');
    } finally {
      if (mounted) setState(() => _subiendo = false);
    }
  }

  /// Reintenta un solo archivo con la sede ya decidida (elegida de la lista o
  /// recién creada). No toca los demás archivos del resultado: cada uno se
  /// resuelve por su cuenta, igual que en la carga original.
  Future<void> _reintentarConSede(String nombreArchivo, int sedeId) async {
    final archivo = _elegidos.where((f) => f.name == nombreArchivo).firstOrNull;
    if (archivo?.bytes == null) return;

    setState(() => _subiendo = true);

    try {
      final respuesta = await ref.read(repositorioProvider).cargarAuditorias(
        [(bytes: archivo!.bytes!, nombre: archivo.name)],
        sedeId: sedeId,
      );

      if (!mounted) return;

      ref.invalidate(consolidadoProvider);
      ref.invalidate(auditoriasProvider);

      final nuevasCargadas =
          ((respuesta['cargadas'] as List?) ?? const []).cast<Map<String, dynamic>>();
      final nuevasFallidas =
          ((respuesta['fallidas'] as List?) ?? const []).cast<Map<String, dynamic>>();

      setState(() {
        final previas = _resultado ?? const {'cargadas': [], 'fallidas': []};
        final cargadasPrevias =
            ((previas['cargadas'] as List?) ?? const []).cast<Map<String, dynamic>>();
        final fallidasPrevias = ((previas['fallidas'] as List?) ?? const [])
            .cast<Map<String, dynamic>>()
            .where((f) => f['archivo'] != nombreArchivo)
            .toList();

        _resultado = {
          'cargadas': [...cargadasPrevias, ...nuevasCargadas],
          'fallidas': [...fallidasPrevias, ...nuevasFallidas],
        };

        if (nuevasCargadas.isNotEmpty) {
          _elegidos = _elegidos.where((f) => f.name != nombreArchivo).toList();
        }
      });
    } on ErrorApi catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(e.mensaje), backgroundColor: Paleta.critico),
        );
      }
    } finally {
      if (mounted) setState(() => _subiendo = false);
    }
  }

  Future<void> _resolverSede(String nombreArchivo, String? textoEncontrado) async {
    final sedeId = await _abrirSelectorDeSede(context, ref, textoEncontrado);
    if (sedeId != null) await _reintentarConSede(nombreArchivo, sedeId);
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(
          title: const Text('Cargar auditoría'),
          actions: [
            IconButton(
              icon: const Icon(Icons.folder_outlined),
              tooltip: 'Archivos cargados',
              onPressed: () => Navigator.of(context).push(
                MaterialPageRoute(builder: (_) => const PantallaAuditorias()),
              ),
            ),
          ],
        ),
        body: ListView(
          padding: const EdgeInsets.fromLTRB(16, 16, 16, 32),
          children: [
            const Aviso(
              icono: Icons.description_outlined,
              mensaje: 'Suba las autoevaluaciones de las sedes: los archivos '
                  '«SUH <sede>.xlsx». La matriz mensual de seguimiento se sube '
                  'desde la pantalla del corte.',
            ),
            const SizedBox(height: 20),
            OutlinedButton.icon(
              onPressed: _subiendo ? null : _elegir,
              icon: const Icon(Icons.attach_file, size: 18),
              label: Text(
                _elegidos.isEmpty
                    ? 'Elegir archivos'
                    : 'Cambiar selección (${_elegidos.length})',
              ),
              style: OutlinedButton.styleFrom(
                padding: const EdgeInsets.symmetric(vertical: 16),
                side: const BorderSide(color: Paleta.regla),
                foregroundColor: Paleta.sello,
              ),
            ),
            if (_elegidos.isNotEmpty) ...[
              const SizedBox(height: 16),
              Bloque(
                padding: EdgeInsets.zero,
                child: Column(
                  children: [
                    for (var i = 0; i < _elegidos.length; i++) ...[
                      if (i > 0) const Divider(height: 1),
                      ListTile(
                        dense: true,
                        leading: const Icon(Icons.table_view_outlined,
                            size: 20, color: Paleta.sello),
                        title: Text(
                          _elegidos[i].name,
                          style: const TextStyle(fontSize: 13.5),
                        ),
                        subtitle: Text(
                          '${(_elegidos[i].size / 1048576).toStringAsFixed(1)} MB',
                          style: const TextStyle(fontSize: 11.5),
                        ),
                        trailing: IconButton(
                          icon: const Icon(Icons.close, size: 18),
                          tooltip: 'Quitar',
                          onPressed: _subiendo
                              ? null
                              : () => setState(() => _elegidos.removeAt(i)),
                        ),
                      ),
                    ],
                  ],
                ),
              ),
              const SizedBox(height: 16),
              FilledButton.icon(
                onPressed: _subiendo ? null : _subir,
                icon: _subiendo
                    ? const SizedBox(
                        height: 16,
                        width: 16,
                        child: CircularProgressIndicator(
                            strokeWidth: 2, color: Colors.white),
                      )
                    : const Icon(Icons.cloud_upload_outlined, size: 18),
                label: Text(_subiendo ? 'Procesando…' : 'Subir y procesar'),
              ),
              if (_subiendo) ...[
                const SizedBox(height: 10),
                const Text(
                  'Se está leyendo el archivo y enfrentando contra los '
                  'hallazgos vigentes de la sede. Puede tardar unos segundos.',
                  style: TextStyle(
                      fontSize: 12, color: Paleta.apagado, height: 1.45),
                ),
              ],
            ],
            if (_error != null) ...[
              const SizedBox(height: 20),
              Aviso(
                mensaje: _error!,
                color: Paleta.critico,
                icono: Icons.error_outline,
              ),
            ],
            if (_resultado != null) ...[
              const SizedBox(height: 24),
              _Resultado(datos: _resultado!, onElegirSede: _resolverSede),
            ],
          ],
        ),
      );
}

/// Trae las sedes existentes y deja elegir una, o registrar la que falte, sin
/// salir de la pantalla de carga. Devuelve el id elegido, o nulo si la
/// persona canceló.
Future<int?> _abrirSelectorDeSede(
  BuildContext context,
  WidgetRef ref,
  String? textoEncontrado,
) async {
  List<Sede> sedes;

  try {
    sedes = await ref.read(repositorioProvider).sedes();
  } on ErrorApi catch (e) {
    if (context.mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(e.mensaje), backgroundColor: Paleta.critico),
      );
    }
    return null;
  }

  if (!context.mounted) return null;

  return showDialog<int>(
    context: context,
    builder: (_) => _DialogoElegirSede(sedes: sedes, textoEncontrado: textoEncontrado),
  );
}

class _DialogoElegirSede extends ConsumerStatefulWidget {
  const _DialogoElegirSede({required this.sedes, this.textoEncontrado});

  final List<Sede> sedes;
  final String? textoEncontrado;

  @override
  ConsumerState<_DialogoElegirSede> createState() => _DialogoElegirSedeEstado();
}

class _DialogoElegirSedeEstado extends ConsumerState<_DialogoElegirSede> {
  bool _creandoNueva = false;
  bool _creando = false;
  String? _errorCreacion;
  final _codigo = TextEditingController();
  final _nombre = TextEditingController();

  @override
  void dispose() {
    _codigo.dispose();
    _nombre.dispose();
    super.dispose();
  }

  Future<void> _crearYUsar() async {
    final codigo = _codigo.text.trim();
    final nombre = _nombre.text.trim();

    if (codigo.isEmpty || nombre.isEmpty) {
      setState(() => _errorCreacion = 'Complete el código y el nombre.');
      return;
    }

    setState(() {
      _creando = true;
      _errorCreacion = null;
    });

    try {
      final sede = await ref
          .read(repositorioProvider)
          .crearSede(codigo: codigo, nombre: nombre);

      if (mounted) Navigator.of(context).pop(sede.id);
    } on ErrorApi catch (e) {
      if (mounted) setState(() => _errorCreacion = e.mensaje);
    } finally {
      if (mounted) setState(() => _creando = false);
    }
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
        title: const Text('Elegir sede'),
        content: SizedBox(
          width: 360,
          child: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                if (widget.textoEncontrado != null) ...[
                  Text(
                    'El archivo dice: «${widget.textoEncontrado}»',
                    style: const TextStyle(fontSize: 12.5, color: Paleta.apagado, height: 1.4),
                  ),
                  const SizedBox(height: 14),
                ],
                if (!_creandoNueva) ...[
                  if (widget.sedes.isEmpty)
                    const Text(
                      'Todavía no hay sedes registradas.',
                      style: TextStyle(fontSize: 13, color: Paleta.apagado),
                    )
                  else
                    ConstrainedBox(
                      constraints: const BoxConstraints(maxHeight: 280),
                      child: ListView.separated(
                        shrinkWrap: true,
                        itemCount: widget.sedes.length,
                        separatorBuilder: (_, __) => const Divider(height: 1),
                        itemBuilder: (_, i) {
                          final sede = widget.sedes[i];
                          return ListTile(
                            dense: true,
                            contentPadding: EdgeInsets.zero,
                            title: Text(sede.nombre, style: const TextStyle(fontSize: 13.5)),
                            subtitle: Text(sede.codigo, style: const TextStyle(fontSize: 11.5)),
                            onTap: () => Navigator.of(context).pop(sede.id),
                          );
                        },
                      ),
                    ),
                  const SizedBox(height: 12),
                  OutlinedButton.icon(
                    onPressed: () => setState(() => _creandoNueva = true),
                    icon: const Icon(Icons.add, size: 17),
                    label: const Text('Registrar una sede nueva'),
                    style: OutlinedButton.styleFrom(
                      foregroundColor: Paleta.sello,
                      side: const BorderSide(color: Paleta.regla),
                      minimumSize: const Size.fromHeight(0),
                    ),
                  ),
                ] else ...[
                  TextField(
                    controller: _codigo,
                    textCapitalization: TextCapitalization.characters,
                    decoration: const InputDecoration(
                      labelText: 'Código',
                      hintText: 'COMUNEROS',
                    ),
                  ),
                  const SizedBox(height: 10),
                  TextField(
                    controller: _nombre,
                    decoration: const InputDecoration(
                      labelText: 'Nombre',
                      hintText: 'Centro de Salud Comuneros',
                    ),
                  ),
                  if (_errorCreacion != null) ...[
                    const SizedBox(height: 10),
                    Text(
                      _errorCreacion!,
                      style: const TextStyle(fontSize: 12.5, color: Paleta.critico),
                    ),
                  ],
                  const SizedBox(height: 4),
                  TextButton.icon(
                    onPressed: _creando ? null : () => setState(() => _creandoNueva = false),
                    icon: const Icon(Icons.arrow_back, size: 16),
                    label: const Text('Elegir de la lista en vez de crear'),
                  ),
                ],
              ],
            ),
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(),
            child: const Text('Cancelar'),
          ),
          if (_creandoNueva)
            FilledButton(
              onPressed: _creando ? null : _crearYUsar,
              child: _creando
                  ? const SizedBox(
                      height: 16,
                      width: 16,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : const Text('Crear y usar'),
            ),
        ],
      );
}

class _Resultado extends ConsumerWidget {
  const _Resultado({required this.datos, required this.onElegirSede});

  final Map<String, dynamic> datos;
  final Future<void> Function(String nombreArchivo, String? textoEncontrado) onElegirSede;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final cargadas = (datos['cargadas'] as List?) ?? const [];
    final fallidas = (datos['fallidas'] as List?) ?? const [];

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (cargadas.isNotEmpty) ...[
          const RotuloSeccion('Procesadas'),
          for (final c in cargadas.cast<Map<String, dynamic>>()) ...[
            _TarjetaCargada(carga: c),
            const SizedBox(height: 10),
          ],
        ],
        if (fallidas.isNotEmpty) ...[
          const SizedBox(height: 8),
          const RotuloSeccion('No se pudieron leer'),
          for (final f in fallidas.cast<Map<String, dynamic>>()) ...[
            _TarjetaFallida(
              fallo: f,
              onElegirSede: f['tipo'] == 'sede_no_identificada'
                  ? () => onElegirSede(
                        f['archivo'] as String,
                        f['texto_encontrado'] as String?,
                      )
                  : null,
            ),
            const SizedBox(height: 10),
          ],
        ],
      ],
    );
  }
}

class _TarjetaCargada extends StatelessWidget {
  const _TarjetaCargada({required this.carga});

  final Map<String, dynamic> carga;

  @override
  Widget build(BuildContext context) {
    final extraccion = (carga['extraccion'] as Map?)?.cast<String, dynamic>() ?? {};
    final reconciliacion =
        (carga['reconciliacion'] as Map?)?.cast<String, dynamic>() ?? {};
    final pendientes = (reconciliacion['pendientes_de_decision'] as num?)?.toInt() ?? 0;
    final advertencias =
        ((extraccion['advertencias'] as List?) ?? const []).cast<String>();

    return Bloque(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  '${carga['sede']}',
                  style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w600),
                ),
              ),
              Pastilla(
                texto: 'Versión ${carga['version']}',
                color: Paleta.sello,
                fondo: Paleta.selloSuave,
              ),
            ],
          ),
          Text(
            '${carga['archivo']}',
            style: const TextStyle(fontSize: 11.5, color: Paleta.apagado),
          ),
          const SizedBox(height: 14),
          Row(
            children: [
              Expanded(
                child: Cifra(
                  valor: '${extraccion['hallazgos'] ?? 0}',
                  rotulo: 'Hallazgos\nextraídos',
                ),
              ),
              Expanded(
                child: Cifra(
                  valor: '${extraccion['criterios'] ?? 0}',
                  rotulo: 'Criterios\nevaluados',
                ),
              ),
              Expanded(
                child: Cifra(
                  valor: '$pendientes',
                  rotulo: 'Decisiones\npendientes',
                  color: pendientes > 0 ? Paleta.alerta : Paleta.apagado,
                ),
              ),
            ],
          ),
          for (final a in advertencias) ...[
            const SizedBox(height: 12),
            Aviso(mensaje: a, color: Paleta.alerta, icono: Icons.warning_amber_outlined),
          ],
          const SizedBox(height: 16),
          FilledButton.icon(
            icon: const Icon(Icons.rule, size: 18),
            label: Text(
              pendientes > 0 ? 'Revisar el cruce' : 'Revisar y publicar',
            ),
            onPressed: () => Navigator.of(context).push(
              MaterialPageRoute(
                builder: (_) => PantallaConfirmarCruce(
                  auditoriaId: (carga['auditoria_id'] as num).toInt(),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// El extractor rechaza en vez de adivinar, y dice hoja y fila exactas.
/// Esa precisión es la diferencia entre una llamada de dos minutos y unas
/// cifras equivocadas que nadie detecta.
class _TarjetaFallida extends StatelessWidget {
  const _TarjetaFallida({required this.fallo, this.onElegirSede});

  final Map<String, dynamic> fallo;

  /// Nulo cuando el archivo está mal formado (EstructuraInvalida): ahí no hay
  /// nada que la persona pueda arreglar desde la pantalla, hay que corregir
  /// el Excel. Cuando no es nulo, es justo lo contrario: el archivo se leyó
  /// bien y solo falta decidir a qué sede pertenece.
  final VoidCallback? onElegirSede;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: Paleta.superficie,
          borderRadius: BorderRadius.circular(4),
          border: Border.all(color: Paleta.critico.withValues(alpha: 0.4)),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                const Icon(Icons.block, size: 17, color: Paleta.critico),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    '${fallo['archivo']}',
                    style: const TextStyle(
                        fontSize: 13.5, fontWeight: FontWeight.w600),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 8),
            Text(
              '${fallo['mensaje'] ?? 'No se pudo leer el archivo.'}',
              style: const TextStyle(fontSize: 13, height: 1.45, color: Paleta.tinta2),
            ),
            if (fallo['hoja'] != null || fallo['fila'] != null) ...[
              const SizedBox(height: 8),
              Text(
                [
                  if (fallo['hoja'] != null) 'Hoja: ${fallo['hoja']}',
                  if (fallo['fila'] != null) 'Fila: ${fallo['fila']}',
                  if (fallo['encontrado'] != null) 'Dice: «${fallo['encontrado']}»',
                ].join('   ·   '),
                style: const TextStyle(
                  fontSize: 12,
                  color: Paleta.critico,
                  fontWeight: FontWeight.w600,
                ),
              ),
            ],
            if (onElegirSede != null) ...[
              const SizedBox(height: 12),
              OutlinedButton.icon(
                onPressed: onElegirSede,
                icon: const Icon(Icons.add_location_alt_outlined, size: 17),
                label: const Text('Elegir sede'),
                style: OutlinedButton.styleFrom(
                  foregroundColor: Paleta.sello,
                  side: const BorderSide(color: Paleta.regla),
                  padding: const EdgeInsets.symmetric(vertical: 10),
                  minimumSize: const Size.fromHeight(0),
                ),
              ),
            ],
          ],
        ),
      );
}
