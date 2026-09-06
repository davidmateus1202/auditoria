import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../datos/repositorios.dart';
import '../dominio/enums.dart';
import '../dominio/modelos.dart';
import '../nucleo/api.dart';
import '../nucleo/tema.dart';
import '../widgets/comunes.dart';
import 'hallazgos.dart' show listaHallazgosProvider;
import 'inicio.dart' show consolidadoProvider;

final lineaTiempoProvider = FutureProvider.autoDispose
    .family<Map<String, dynamic>, int>((ref, id) async {
  return ref.watch(repositorioProvider).lineaTiempo(id);
});

/// Detalle del hallazgo con su historia: cuándo nació, por qué estados pasó
/// mes a mes y con qué evidencia se cerró.
class PantallaDetalleHallazgo extends ConsumerWidget {
  const PantallaDetalleHallazgo({super.key, required this.hallazgo});

  final Hallazgo hallazgo;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final historia = ref.watch(lineaTiempoProvider(hallazgo.id));

    return Scaffold(
      appBar: AppBar(title: const Text('Hallazgo')),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(16, 16, 16, 100),
        children: [
          Row(
            children: [
              Pastilla.estado(context, hallazgo.estado),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  hallazgo.nombreEstandar,
                  style: const TextStyle(
                    fontSize: 12,
                    fontWeight: FontWeight.w600,
                    color: Paleta.apagado,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          Text(
            hallazgo.descripcion,
            style: const TextStyle(fontSize: 16, height: 1.5),
          ),
          const SizedBox(height: 20),
          Bloque(
            padding: EdgeInsets.zero,
            child: Column(
              children: [
                _Dato(rotulo: 'Sede', valor: hallazgo.sedeNombre),
                if (hallazgo.servicio != null)
                  _Dato(rotulo: 'Servicio', valor: hallazgo.servicio!),
                _Dato(
                  rotulo: 'Acción propuesta',
                  valor: hallazgo.accionPropuesta ?? 'Sin registrar',
                  atenuado: hallazgo.accionPropuesta == null,
                ),
                _Dato(
                  rotulo: 'Responsable',
                  valor: hallazgo.responsable ?? 'Sin asignar',
                  atenuado: hallazgo.responsable == null,
                ),
                _Dato(
                  rotulo: 'Evidencia',
                  valor: hallazgo.evidencia ?? 'Sin evidencia registrada',
                  atenuado: hallazgo.evidencia == null,
                  ultimo: true,
                ),
              ],
            ),
          ),
          const SizedBox(height: 24),
          const RotuloSeccion('Paso por cada corte'),
          historia.when(
            loading: () => const Padding(
              padding: EdgeInsets.all(24),
              child: Center(child: CircularProgressIndicator()),
            ),
            error: (e, _) => Aviso(
              mensaje: 'No se pudo cargar la historia: $e',
              color: Paleta.critico,
              icono: Icons.error_outline,
            ),
            data: (datos) => _LineaTiempo(datos: datos),
          ),
        ],
      ),
      bottomNavigationBar: _BarraAcciones(hallazgo: hallazgo),
    );
  }
}

class _Dato extends StatelessWidget {
  const _Dato({
    required this.rotulo,
    required this.valor,
    this.atenuado = false,
    this.ultimo = false,
  });

  final String rotulo;
  final String valor;
  final bool atenuado;
  final bool ultimo;

  @override
  Widget build(BuildContext context) => Container(
        width: double.infinity,
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
        decoration: BoxDecoration(
          border: ultimo
              ? null
              : const Border(bottom: BorderSide(color: Paleta.regla)),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              rotulo.toUpperCase(),
              style: const TextStyle(
                fontSize: 10,
                fontWeight: FontWeight.w700,
                letterSpacing: 0.9,
                color: Paleta.apagado,
              ),
            ),
            const SizedBox(height: 4),
            Text(
              valor,
              style: TextStyle(
                fontSize: 14,
                height: 1.4,
                color: atenuado ? Paleta.apagado : Paleta.tinta,
                fontStyle: atenuado ? FontStyle.italic : FontStyle.normal,
              ),
            ),
          ],
        ),
      );
}

class _LineaTiempo extends StatelessWidget {
  const _LineaTiempo({required this.datos});

  final Map<String, dynamic> datos;

  @override
  Widget build(BuildContext context) {
    final porCorte = (datos['por_corte'] as List?) ?? const [];

    if (porCorte.isEmpty) {
      return const Aviso(
        mensaje: 'Este hallazgo todavía no ha pasado por ningún corte cerrado.',
        icono: Icons.timelapse_outlined,
      );
    }

    return Bloque(
      padding: EdgeInsets.zero,
      child: Column(
        children: [
          for (var i = 0; i < porCorte.length; i++) ...[
            if (i > 0) const Divider(height: 1),
            _PasoCorte(
              datos: (porCorte[i] as Map).cast<String, dynamic>(),
              esUltimo: i == porCorte.length - 1,
            ),
          ],
        ],
      ),
    );
  }
}

class _PasoCorte extends StatelessWidget {
  const _PasoCorte({required this.datos, required this.esUltimo});

  final Map<String, dynamic> datos;
  final bool esUltimo;

  @override
  Widget build(BuildContext context) {
    final estado = EstadoHallazgo.desde(datos['estado'] as String?);
    final periodo = ((datos['corte'] as Map?)?['periodo'] as String?) ?? '';
    final presente = datos['presente_en_corte'] == true;
    final meses = datos['meses_abierto'] as int? ?? 0;

    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      child: Row(
        children: [
          Container(
            width: 8,
            height: 8,
            decoration: BoxDecoration(
              color: estado.color(context),
              shape: BoxShape.circle,
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  Formato.periodo(periodo),
                  style: const TextStyle(fontSize: 13.5, fontWeight: FontWeight.w600),
                ),
                if (!presente)
                  const Text(
                    'No se reportó en este corte',
                    style: TextStyle(
                      fontSize: 11.5,
                      color: Paleta.alerta,
                      fontStyle: FontStyle.italic,
                    ),
                  )
                else if (meses > 1)
                  Text(
                    'Lleva $meses meses abierto',
                    style: const TextStyle(fontSize: 11.5, color: Paleta.apagado),
                  ),
              ],
            ),
          ),
          Pastilla.estado(context, estado),
        ],
      ),
    );
  }
}

/// Cambiar el estado. Cerrar exige evidencia: sin constancia de con qué se
/// cerró, el consolidado no se puede defender.
class _BarraAcciones extends ConsumerWidget {
  const _BarraAcciones({required this.hallazgo});

  final Hallazgo hallazgo;

  @override
  Widget build(BuildContext context, WidgetRef ref) => Container(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 20),
        decoration: const BoxDecoration(
          color: Paleta.superficie,
          border: Border(top: BorderSide(color: Paleta.regla)),
        ),
        child: SafeArea(
          top: false,
          child: FilledButton.icon(
            icon: const Icon(Icons.edit_outlined, size: 18),
            label: const Text('Registrar seguimiento'),
            onPressed: () => _abrirFormulario(context, ref),
          ),
        ),
      );

  Future<void> _abrirFormulario(BuildContext context, WidgetRef ref) async {
    final cambio = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (_) => _FormularioSeguimiento(hallazgo: hallazgo),
    );

    if (cambio == true) {
      ref.invalidate(lineaTiempoProvider(hallazgo.id));
      ref.invalidate(listaHallazgosProvider);
      ref.invalidate(consolidadoProvider);

      if (context.mounted) Navigator.of(context).pop();
    }
  }
}

class _FormularioSeguimiento extends ConsumerStatefulWidget {
  const _FormularioSeguimiento({required this.hallazgo});

  final Hallazgo hallazgo;

  @override
  ConsumerState<_FormularioSeguimiento> createState() =>
      _FormularioSeguimientoEstado();
}

class _FormularioSeguimientoEstado
    extends ConsumerState<_FormularioSeguimiento> {
  late EstadoHallazgo _estado = widget.hallazgo.estado;
  final _evidencia = TextEditingController();
  final _accion = TextEditingController();
  bool _enviando = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _accion.text = widget.hallazgo.accionPropuesta ?? '';
    _evidencia.text = widget.hallazgo.evidencia ?? '';
  }

  @override
  void dispose() {
    _evidencia.dispose();
    _accion.dispose();
    super.dispose();
  }

  bool get _exigeEvidencia => _estado == EstadoHallazgo.cerrado;

  Future<void> _guardar() async {
    if (_exigeEvidencia && _evidencia.text.trim().isEmpty) {
      setState(() => _error =
          'Cerrar un hallazgo exige registrar con qué evidencia se cerró.');
      return;
    }

    setState(() {
      _enviando = true;
      _error = null;
    });

    try {
      await ref.read(repositorioProvider).cambiarEstado(
            widget.hallazgo.id,
            estado: _estado,
            evidencia: _evidencia.text.trim(),
            accionPropuesta: _accion.text.trim(),
          );

      if (mounted) Navigator.pop(context, true);
    } on ErrorApi catch (e) {
      if (mounted) setState(() => _error = e.mensaje);
    } finally {
      if (mounted) setState(() => _enviando = false);
    }
  }

  @override
  Widget build(BuildContext context) => Padding(
        padding: EdgeInsets.fromLTRB(
          20,
          0,
          20,
          MediaQuery.of(context).viewInsets.bottom + 24,
        ),
        child: SingleChildScrollView(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            mainAxisSize: MainAxisSize.min,
            children: [
              const Text(
                'Registrar seguimiento',
                style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700),
              ),
              const SizedBox(height: 18),
              const Text(
                'ESTADO',
                style: TextStyle(
                  fontSize: 10,
                  fontWeight: FontWeight.w700,
                  letterSpacing: 0.9,
                  color: Paleta.apagado,
                ),
              ),
              const SizedBox(height: 8),
              Wrap(
                spacing: 8,
                runSpacing: 8,
                children: [
                  for (final estado in EstadoHallazgo.values)
                    if (estado != EstadoHallazgo.sinDato)
                      ChoiceChip(
                        label: Text(estado.etiqueta),
                        selected: _estado == estado,
                        selectedColor: estado.fondo(context),
                        side: BorderSide(
                          color: _estado == estado ? estado.color(context) : Paleta.regla,
                        ),
                        labelStyle: TextStyle(
                          fontSize: 13,
                          color: _estado == estado ? estado.color(context) : Paleta.tinta2,
                          fontWeight:
                              _estado == estado ? FontWeight.w600 : FontWeight.w400,
                        ),
                        onSelected: (_) => setState(() => _estado = estado),
                      ),
                ],
              ),
              const SizedBox(height: 18),
              TextField(
                controller: _accion,
                maxLines: 2,
                decoration: const InputDecoration(
                  labelText: 'Acción propuesta',
                  alignLabelWithHint: true,
                ),
              ),
              const SizedBox(height: 14),
              TextField(
                controller: _evidencia,
                maxLines: 3,
                decoration: InputDecoration(
                  labelText: _exigeEvidencia
                      ? 'Evidencia del cumplimiento (obligatoria)'
                      : 'Evidencia',
                  alignLabelWithHint: true,
                  helperText: _exigeEvidencia
                      ? 'Un cierre sin constancia hace indefendible el consolidado.'
                      : null,
                  helperMaxLines: 2,
                ),
              ),
              if (_error != null) ...[
                const SizedBox(height: 14),
                Aviso(
                  mensaje: _error!,
                  color: Paleta.critico,
                  icono: Icons.error_outline,
                ),
              ],
              const SizedBox(height: 22),
              FilledButton(
                onPressed: _enviando ? null : _guardar,
                child: _enviando
                    ? const SizedBox(
                        height: 18,
                        width: 18,
                        child: CircularProgressIndicator(
                          strokeWidth: 2,
                          color: Colors.white,
                        ),
                      )
                    : const Text('Guardar'),
              ),
            ],
          ),
        ),
      );
}
