import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../datos/repositorios.dart';
import '../dominio/enums.dart';
import '../nucleo/api.dart';
import '../nucleo/tema.dart';
import '../widgets/comunes.dart';
import 'inicio.dart' show consolidadoProvider;

final cruceProvider = FutureProvider.autoDispose
    .family<Map<String, dynamic>, int>((ref, id) async {
  return ref.watch(repositorioProvider).reconciliacionDeAuditoria(id);
});

/// Confirmar el cruce: qué persiste, qué nace y qué se propone cerrar.
///
/// Los destinos que exigen decisión van primero y bloquean la publicación
/// hasta resolverse. Cerrar un hallazgo porque un archivo dejó de mencionarlo
/// es exactamente la decisión que no puede tomarse sola.
class PantallaConfirmarCruce extends ConsumerStatefulWidget {
  const PantallaConfirmarCruce({super.key, required this.auditoriaId});

  final int auditoriaId;

  @override
  ConsumerState<PantallaConfirmarCruce> createState() =>
      _PantallaConfirmarCruceEstado();
}

class _PantallaConfirmarCruceEstado
    extends ConsumerState<PantallaConfirmarCruce> {
  /// id de reconciliación → decisión tomada
  final Map<int, Map<String, dynamic>> _decisiones = {};
  bool _publicando = false;

  Future<void> _publicar(int pendientes) async {
    if (_decisiones.length < pendientes) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            'Faltan ${pendientes - _decisiones.length} decisiones por tomar.',
          ),
          backgroundColor: Paleta.alerta,
        ),
      );

      return;
    }

    setState(() => _publicando = true);

    try {
      final r = await ref
          .read(repositorioProvider)
          .confirmarAuditoria(widget.auditoriaId, _decisiones);

      if (!mounted) return;

      ref.invalidate(consolidadoProvider);
      ref.invalidate(auditoriasProvider);

      final aplicado = (r['aplicado'] as Map?)?.cast<String, dynamic>() ?? {};

      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            'Auditoría publicada: ${aplicado['nuevos'] ?? 0} nuevos, '
            '${aplicado['persiste'] ?? 0} vigentes, '
            '${aplicado['cerrados'] ?? 0} cerrados.',
          ),
        ),
      );

      Navigator.of(context).pop(true);
    } on ErrorApi catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(e.mensaje), backgroundColor: Paleta.critico),
        );
      }
    } finally {
      if (mounted) setState(() => _publicando = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final cruce = ref.watch(cruceProvider(widget.auditoriaId));

    return Scaffold(
      appBar: AppBar(title: const Text('Confirmar el cruce')),
      body: cruce.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (e, _) => PantallaError(
          error: e,
          reintentar: () => ref.invalidate(cruceProvider(widget.auditoriaId)),
        ),
        data: (datos) {
          final pendientes =
              (datos['pendientes_de_decision'] as num?)?.toInt() ?? 0;
          final porDestino =
              (datos['por_destino'] as Map?)?.cast<String, dynamic>() ?? {};

          if (porDestino.isEmpty) {
            return const SinContenido(
              icono: Icons.check_circle_outline,
              titulo: 'Nada por revisar',
              detalle: 'Esta auditoría ya fue confirmada.',
            );
          }

          // Lo que exige decisión va arriba: es lo que bloquea la publicación.
          final orden = [
            DestinoReconciliacion.conflicto,
            DestinoReconciliacion.candidatoCierre,
            DestinoReconciliacion.reincidencia,
            DestinoReconciliacion.revision,
            DestinoReconciliacion.noVerificado,
            DestinoReconciliacion.nuevo,
            DestinoReconciliacion.persiste,
          ];

          return ListView(
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 110),
            children: [
              _Cabecera(datos: datos, pendientes: pendientes),
              const SizedBox(height: 20),
              for (final destino in orden)
                if (porDestino[destino.valor] case final List filas
                    when filas.isNotEmpty) ...[
                  _Grupo(
                    destino: destino,
                    filas: filas.cast<Map<String, dynamic>>(),
                    decisiones: _decisiones,
                    onDecidir: (id, decision) =>
                        setState(() => _decisiones[id] = decision),
                  ),
                  const SizedBox(height: 18),
                ],
            ],
          );
        },
      ),
      bottomNavigationBar: cruce.valueOrNull == null
          ? null
          : _BarraPublicar(
              pendientes:
                  (cruce.value!['pendientes_de_decision'] as num?)?.toInt() ?? 0,
              tomadas: _decisiones.length,
              publicando: _publicando,
              onPublicar: _publicar,
            ),
    );
  }
}

class _Cabecera extends StatelessWidget {
  const _Cabecera({required this.datos, required this.pendientes});

  final Map<String, dynamic> datos;
  final int pendientes;

  @override
  Widget build(BuildContext context) {
    final auditoria = (datos['auditoria'] as Map?)?.cast<String, dynamic>() ?? {};

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          '${auditoria['sede']}',
          style: const TextStyle(fontSize: 19, fontWeight: FontWeight.w700),
        ),
        Text(
          'Versión ${auditoria['version']} · ${auditoria['periodo']}',
          style: const TextStyle(fontSize: 12.5, color: Paleta.apagado),
        ),
        const SizedBox(height: 14),
        if (pendientes == 0)
          const Aviso(
            icono: Icons.check_circle_outline,
            color: Paleta.bien,
            mensaje: 'Nada exige decisión: se puede publicar directamente.',
          )
        else
          Aviso(
            icono: Icons.pending_actions_outlined,
            color: Paleta.alerta,
            mensaje: '$pendientes ${pendientes == 1 ? "hallazgo necesita" : "hallazgos necesitan"} '
                'una decisión. Nada se aplica hasta resolverlos.',
          ),
      ],
    );
  }
}

class _Grupo extends StatelessWidget {
  const _Grupo({
    required this.destino,
    required this.filas,
    required this.decisiones,
    required this.onDecidir,
  });

  final DestinoReconciliacion destino;
  final List<Map<String, dynamic>> filas;
  final Map<int, Map<String, dynamic>> decisiones;
  final void Function(int, Map<String, dynamic>) onDecidir;

  static const _explicacion = {
    DestinoReconciliacion.persiste:
        'El mismo problema sigue reportado. Se mantiene abierto y suma una aparición.',
    DestinoReconciliacion.nuevo:
        'No corresponde a ningún hallazgo vigente ni cerrado de la sede.',
    DestinoReconciliacion.reincidencia:
        'Figuraba como cerrado y vuelve a reportarse. Un cierre que no resolvió nada es la señal más valiosa que da el sistema.',
    DestinoReconciliacion.candidatoCierre:
        'El estándar se auditó y el problema ya no aparece. Cerrar exige registrar la evidencia.',
    DestinoReconciliacion.noVerificado:
        'El estándar no se evaluó en esta auditoría, así que sigue abierto sin verificar.',
    DestinoReconciliacion.revision:
        'Se parece a un hallazgo vigente, pero no lo suficiente para vincularlos sin mirar.',
    DestinoReconciliacion.conflicto:
        'El mismo hallazgo se tocó por dos lados a la vez.',
  };

  @override
  Widget build(BuildContext context) => Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Pastilla(texto: destino.etiqueta, color: destino.color()),
              const SizedBox(width: 8),
              Text(
                '${filas.length}',
                style: const TextStyle(
                  fontSize: 13,
                  fontWeight: FontWeight.w700,
                  fontFeatures: [FontFeature.tabularFigures()],
                ),
              ),
              const SizedBox(width: 10),
              const Expanded(child: Divider(height: 1)),
            ],
          ),
          const SizedBox(height: 6),
          Text(
            _explicacion[destino] ?? '',
            style: const TextStyle(fontSize: 12, color: Paleta.apagado, height: 1.4),
          ),
          const SizedBox(height: 10),
          for (final fila in filas) ...[
            _Fila(
              destino: destino,
              fila: fila,
              decision: decisiones[(fila['id'] as num).toInt()],
              onDecidir: onDecidir,
            ),
            const SizedBox(height: 8),
          ],
        ],
      );
}

class _Fila extends StatelessWidget {
  const _Fila({
    required this.destino,
    required this.fila,
    required this.decision,
    required this.onDecidir,
  });

  final DestinoReconciliacion destino;
  final Map<String, dynamic> fila;
  final Map<String, dynamic>? decision;
  final void Function(int, Map<String, dynamic>) onDecidir;

  @override
  Widget build(BuildContext context) {
    final id = (fila['id'] as num).toInt();
    final entrante = fila['texto_entrante'] as String?;
    final vigente = fila['texto_vigente'] as String?;
    final similitud = (fila['similitud'] as num?)?.toDouble();

    return Bloque(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (entrante != null)
            Text(entrante, style: const TextStyle(fontSize: 13.5, height: 1.4)),
          // Cuando hay dos versiones del mismo problema, se muestran las dos:
          // el auditor tiene que poder ver contra qué se comparó.
          if (vigente != null && vigente != entrante) ...[
            const SizedBox(height: 10),
            Container(
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(
                color: Paleta.hundido,
                borderRadius: BorderRadius.circular(3),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      const Text(
                        'YA REGISTRADO',
                        style: TextStyle(
                          fontSize: 9.5,
                          fontWeight: FontWeight.w700,
                          letterSpacing: 0.8,
                          color: Paleta.apagado,
                        ),
                      ),
                      if (similitud != null) ...[
                        const Spacer(),
                        Text(
                          'parecido ${(similitud * 100).round()} %',
                          style: const TextStyle(
                              fontSize: 10.5, color: Paleta.apagado),
                        ),
                      ],
                    ],
                  ),
                  const SizedBox(height: 5),
                  Text(
                    vigente,
                    style: const TextStyle(
                        fontSize: 12.5, height: 1.4, color: Paleta.tinta2),
                  ),
                ],
              ),
            ),
          ],
          if (destino.exigeConfirmacion) ...[
            const SizedBox(height: 12),
            _Acciones(
              destino: destino,
              id: id,
              decision: decision,
              onDecidir: onDecidir,
            ),
          ],
        ],
      ),
    );
  }
}

class _Acciones extends StatelessWidget {
  const _Acciones({
    required this.destino,
    required this.id,
    required this.decision,
    required this.onDecidir,
  });

  final DestinoReconciliacion destino;
  final int id;
  final Map<String, dynamic>? decision;
  final void Function(int, Map<String, dynamic>) onDecidir;

  /// (acción enviada al servidor, texto del botón)
  static const _opciones = {
    DestinoReconciliacion.candidatoCierre: [
      ('cerrar', 'Cerrar con evidencia'),
      ('mantener', 'Mantener abierto'),
    ],
    DestinoReconciliacion.reincidencia: [
      ('reabrir', 'Reabrir'),
      ('nuevo', 'Registrar como nuevo'),
    ],
    DestinoReconciliacion.revision: [
      ('vincular', 'Es el mismo'),
      ('nuevo', 'Es uno nuevo'),
      ('descartar', 'Descartar'),
    ],
    DestinoReconciliacion.conflicto: [
      ('conservar_app', 'Conservar la app'),
      ('tomar_excel', 'Tomar el Excel'),
    ],
  };

  @override
  Widget build(BuildContext context) {
    final opciones = _opciones[destino] ?? const [];
    final elegida = decision?['accion'] as String?;

    return Wrap(
      spacing: 8,
      runSpacing: 8,
      children: [
        for (final (accion, etiqueta) in opciones)
          ChoiceChip(
            label: Text(etiqueta),
            selected: elegida == accion,
            selectedColor: Paleta.selloSuave,
            side: BorderSide(
              color: elegida == accion ? Paleta.sello : Paleta.regla,
            ),
            labelStyle: TextStyle(
              fontSize: 12.5,
              color: elegida == accion ? Paleta.sello : Paleta.tinta2,
              fontWeight: elegida == accion ? FontWeight.w600 : FontWeight.w400,
            ),
            onSelected: (_) async {
              // Cerrar exige evidencia: sin constancia de con qué se cerró, el
              // consolidado no se puede defender.
              if (accion == 'cerrar') {
                final evidencia = await _pedirEvidencia(context);
                if (evidencia == null) return;

                onDecidir(id, {'accion': 'cerrar', 'evidencia': evidencia});

                return;
              }

              if (accion == 'vincular') {
                onDecidir(id, {
                  'accion': 'vincular',
                  'hallazgo_id': decision?['hallazgo_id'],
                });

                return;
              }

              onDecidir(id, {'accion': accion});
            },
          ),
        if (elegida == 'cerrar' && decision?['evidencia'] != null)
          Padding(
            padding: const EdgeInsets.only(top: 4),
            child: Text(
              'Evidencia: ${decision!['evidencia']}',
              style: const TextStyle(
                  fontSize: 11.5, color: Paleta.bien, fontStyle: FontStyle.italic),
            ),
          ),
      ],
    );
  }

  Future<String?> _pedirEvidencia(BuildContext context) async {
    final controlador = TextEditingController();

    final texto = await showDialog<String>(
      context: context,
      builder: (_) => AlertDialog(
        title: const Text('¿Con qué evidencia se cierra?'),
        content: TextField(
          controller: controlador,
          maxLines: 3,
          autofocus: true,
          decoration: const InputDecoration(
            hintText: 'Acta, contrato, registro fotográfico…',
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: const Text('Cancelar'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, controlador.text.trim()),
            child: const Text('Cerrar el hallazgo'),
          ),
        ],
      ),
    );

    controlador.dispose();

    return (texto == null || texto.isEmpty) ? null : texto;
  }
}

class _BarraPublicar extends StatelessWidget {
  const _BarraPublicar({
    required this.pendientes,
    required this.tomadas,
    required this.publicando,
    required this.onPublicar,
  });

  final int pendientes;
  final int tomadas;
  final bool publicando;
  final void Function(int) onPublicar;

  @override
  Widget build(BuildContext context) {
    final listo = tomadas >= pendientes;

    return Container(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 16),
      decoration: const BoxDecoration(
        color: Paleta.superficie,
        border: Border(top: BorderSide(color: Paleta.regla)),
      ),
      child: SafeArea(
        top: false,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            if (pendientes > 0) ...[
              Text(
                '$tomadas de $pendientes decisiones tomadas',
                style: TextStyle(
                  fontSize: 12,
                  fontWeight: FontWeight.w600,
                  color: listo ? Paleta.bien : Paleta.alerta,
                ),
              ),
              const SizedBox(height: 8),
            ],
            SizedBox(
              width: double.infinity,
              child: FilledButton.icon(
                onPressed: publicando ? null : () => onPublicar(pendientes),
                icon: publicando
                    ? const SizedBox(
                        height: 16,
                        width: 16,
                        child: CircularProgressIndicator(
                            strokeWidth: 2, color: Colors.white),
                      )
                    : const Icon(Icons.publish_outlined, size: 18),
                label: Text(publicando ? 'Publicando…' : 'Publicar auditoría'),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
