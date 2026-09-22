import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../datos/repositorios.dart';
import '../nucleo/api.dart';
import '../nucleo/tema.dart';
import '../widgets/comunes.dart';
import 'cargar_auditoria.dart';
import 'inicio.dart' show consolidadoProvider;

/// Qué archivos SUH hay cargados, sede por sede, con la opción de borrar.
///
/// Cada carga es una versión nueva y las anteriores se conservan (así se
/// puede ver la evolución), así que una misma sede puede aparecer varias
/// veces aquí. Borrar solo tumba la versión elegida: si tiene hallazgos que
/// dependen de ella, el servidor lo rechaza en vez de dejar el consolidado
/// sin respaldo.
class PantallaAuditorias extends ConsumerWidget {
  const PantallaAuditorias({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final auditorias = ref.watch(auditoriasProvider);

    return Scaffold(
      appBar: AppBar(
        title: const Text('Archivos cargados'),
        actions: [
          IconButton(
            icon: const Icon(Icons.upload_file_outlined),
            tooltip: 'Cargar auditoría',
            onPressed: () => Navigator.of(context).push(
              MaterialPageRoute(builder: (_) => const PantallaCargarAuditoria()),
            ),
          ),
          IconButton(
            icon: const Icon(Icons.refresh),
            tooltip: 'Actualizar',
            onPressed: () => ref.invalidate(auditoriasProvider),
          ),
        ],
      ),
      body: auditorias.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (e, _) => PantallaError(
          error: e,
          reintentar: () => ref.invalidate(auditoriasProvider),
        ),
        data: (lista) {
          if (lista.isEmpty) {
            return SinContenido(
              icono: Icons.folder_off_outlined,
              titulo: 'Todavía no hay nada cargado',
              detalle: 'Suba la autoevaluación de una sede para verla aquí.',
              accion: FilledButton.icon(
                icon: const Icon(Icons.upload_file_outlined, size: 18),
                label: const Text('Cargar auditoría'),
                onPressed: () => Navigator.of(context).push(
                  MaterialPageRoute(builder: (_) => const PantallaCargarAuditoria()),
                ),
              ),
            );
          }

          return RefreshIndicator(
            onRefresh: () async => ref.invalidate(auditoriasProvider),
            child: ListView(
              padding: const EdgeInsets.fromLTRB(16, 16, 16, 32),
              children: [
                for (final a in lista) ...[
                  _TarjetaAuditoria(auditoria: a),
                  const SizedBox(height: 10),
                ],
              ],
            ),
          );
        },
      ),
    );
  }
}

class _TarjetaAuditoria extends ConsumerStatefulWidget {
  const _TarjetaAuditoria({required this.auditoria});

  final Map<String, dynamic> auditoria;

  @override
  ConsumerState<_TarjetaAuditoria> createState() => _TarjetaAuditoriaEstado();
}

class _TarjetaAuditoriaEstado extends ConsumerState<_TarjetaAuditoria> {
  bool _borrando = false;

  Map<String, dynamic> get _sede =>
      (widget.auditoria['sede'] as Map?)?.cast<String, dynamic>() ?? const {};

  String get _estado => '${widget.auditoria['estado']}';

  Color _colorEstado() => switch (_estado) {
        'publicada' => Paleta.bien,
        'por_confirmar' => Paleta.alerta,
        'fallida' => Paleta.critico,
        _ => Paleta.apagado,
      };

  String _etiquetaEstado() => switch (_estado) {
        'publicada' => 'Publicada',
        'por_confirmar' => 'Por confirmar',
        'procesando' => 'Procesando',
        'fallida' => 'Fallida',
        _ => _estado,
      };

  Future<void> _borrar() async {
    final auditoriaId = (widget.auditoria['id'] as num).toInt();
    final nombreSede = '${_sede['nombre'] ?? 'esta sede'}';

    final confirmado = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: const Text('¿Eliminar esta carga?'),
        content: Text(
          '$nombreSede · versión ${widget.auditoria['version']}. '
          'Esto no se puede deshacer.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(dialogContext, false),
            child: const Text('Cancelar'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(dialogContext, true),
            style: FilledButton.styleFrom(backgroundColor: Paleta.critico),
            child: const Text('Eliminar'),
          ),
        ],
      ),
    );

    if (confirmado != true) return;

    setState(() => _borrando = true);

    try {
      await ref.read(repositorioProvider).eliminarAuditoria(auditoriaId);

      if (!mounted) return;

      ref.invalidate(auditoriasProvider);
      ref.invalidate(consolidadoProvider);

      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('$nombreSede eliminada.')),
      );
    } on ErrorApi catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(e.mensaje), backgroundColor: Paleta.critico),
        );
      }
    } finally {
      if (mounted) setState(() => _borrando = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final apariciones = (widget.auditoria['apariciones_count'] as num?)?.toInt() ?? 0;
    final criterios = (widget.auditoria['criterios_count'] as num?)?.toInt() ?? 0;
    final esLineaBase = widget.auditoria['origen'] == 'linea_base';

    return Bloque(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  '${_sede['nombre'] ?? '—'}',
                  style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w600),
                ),
              ),
              IconButton(
                icon: _borrando
                    ? const SizedBox(
                        height: 16,
                        width: 16,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : const Icon(Icons.delete_outline, size: 20, color: Paleta.critico),
                tooltip: 'Eliminar',
                onPressed: _borrando ? null : _borrar,
              ),
            ],
          ),
          Wrap(
            spacing: 6,
            runSpacing: 6,
            children: [
              Pastilla(
                texto: 'Versión ${widget.auditoria['version']}',
                color: Paleta.sello,
                fondo: Paleta.selloSuave,
              ),
              Pastilla(texto: _etiquetaEstado(), color: _colorEstado()),
              if (esLineaBase)
                const Pastilla(texto: 'Línea base', color: Paleta.tinta2),
              if (widget.auditoria['periodo'] != null)
                Pastilla(
                  texto: '${widget.auditoria['periodo']}',
                  color: Paleta.apagado,
                ),
            ],
          ),
          const SizedBox(height: 10),
          Text(
            '$criterios criterios evaluados · $apariciones hallazgos vinculados',
            style: const TextStyle(fontSize: 12, color: Paleta.apagado),
          ),
        ],
      ),
    );
  }
}
