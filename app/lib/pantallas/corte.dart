import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../datos/repositorios.dart';
import '../dominio/modelos.dart';
import '../nucleo/api.dart';
import '../nucleo/tema.dart';
import '../widgets/comunes.dart';
import 'inicio.dart' show consolidadoProvider;

final cortesProvider = FutureProvider.autoDispose<List<Corte>>(
  (ref) => ref.watch(repositorioProvider).cortes(),
);

final detalleCorteProvider =
    FutureProvider.autoDispose.family<Corte, String>((ref, periodo) async {
  return ref.watch(repositorioProvider).corte(periodo);
});

/// El mes de seguimiento: qué sedes ya reportaron y qué falta.
///
/// El ida y vuelta con Excel es permanente, no una etapa de transición: desde
/// aquí se descarga la matriz, se edita afuera y se vuelve a subir; o se editan
/// los estados sin salir de la app. Las dos vías escriben sobre el mismo corte.
class PantallaCorte extends ConsumerWidget {
  const PantallaCorte({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final cortes = ref.watch(cortesProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Corte del mes')),
      body: cortes.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (e, _) =>
            PantallaError(error: e, reintentar: () => ref.invalidate(cortesProvider)),
        data: (lista) {
          if (lista.isEmpty) {
            return const SinContenido(
              icono: Icons.calendar_month_outlined,
              titulo: 'Todavía no hay ningún corte',
              detalle: 'Importe la matriz de seguimiento desde el servidor para '
                  'abrir el primer mes.',
            );
          }

          final abierto = lista.where((c) => c.abierto).firstOrNull;

          return RefreshIndicator(
            onRefresh: () async {
              ref.invalidate(cortesProvider);
              if (abierto != null) {
                ref.invalidate(detalleCorteProvider(abierto.periodo));
              }
            },
            child: ListView(
              padding: const EdgeInsets.fromLTRB(16, 16, 16, 32),
              children: [
                if (abierto == null)
                  const Aviso(
                    icono: Icons.lock_outline,
                    mensaje: 'No hay ningún mes abierto. Todos los cortes '
                        'registrados están cerrados y sus cifras congeladas.',
                  )
                else
                  _MesAbierto(periodo: abierto.periodo),
                const SizedBox(height: 24),
                const RotuloSeccion('Meses registrados'),
                Bloque(
                  padding: EdgeInsets.zero,
                  child: Column(
                    children: [
                      for (var i = 0; i < lista.length; i++) ...[
                        if (i > 0) const Divider(height: 1),
                        ListTile(
                          leading: Icon(
                            lista[i].abierto
                                ? Icons.edit_calendar_outlined
                                : Icons.event_available_outlined,
                            color: lista[i].abierto ? Paleta.alerta : Paleta.bien,
                          ),
                          title: Text(Formato.periodo(lista[i].periodo)),
                          subtitle: Text(
                            lista[i].abierto
                                ? 'Abierto · admite cambios'
                                : 'Cerrado · cifras congeladas',
                            style: const TextStyle(fontSize: 12),
                          ),
                        ),
                      ],
                    ],
                  ),
                ),
              ],
            ),
          );
        },
      ),
    );
  }
}

class _MesAbierto extends ConsumerStatefulWidget {
  const _MesAbierto({required this.periodo});

  final String periodo;

  @override
  ConsumerState<_MesAbierto> createState() => _MesAbiertoEstado();
}

class _MesAbiertoEstado extends ConsumerState<_MesAbierto> {
  bool _trabajando = false;

  Future<void> _descargarMatriz() async {
    setState(() => _trabajando = true);

    try {
      final bytes =
          await ref.read(repositorioProvider).descargarMatriz(widget.periodo);
      _avisar('Matriz de ${Formato.periodo(widget.periodo)} generada '
          '(${(bytes.length / 1024).round()} KB).');
    } catch (e) {
      _avisar('No se pudo descargar: $e', error: true);
    } finally {
      if (mounted) setState(() => _trabajando = false);
    }
  }

  Future<void> _subirMatriz() async {
    final elegido = await FilePicker.pickFiles(
      type: FileType.custom,
      allowedExtensions: const ['xlsx', 'xls'],
      withData: false,
    );

    final archivo = elegido?.files.firstOrNull;
    if (archivo?.path == null) return;

    setState(() => _trabajando = true);

    try {
      final resumen = await ref.read(repositorioProvider).subirMatriz(
            widget.periodo,
            archivo!.path!,
            archivo.name,
          );

      if (!mounted) return;

      ref.invalidate(detalleCorteProvider(widget.periodo));
      ref.invalidate(consolidadoProvider);

      await showDialog<void>(
        context: context,
        builder: (_) => _ResumenCarga(resumen: resumen),
      );
    } on ErrorApi catch (e) {
      // El backend rechaza los archivos que no tienen la estructura esperada
      // indicando hoja y fila: ese mensaje llega entero a la pantalla.
      _avisar(e.mensaje, error: true);
    } catch (e) {
      _avisar('No se pudo procesar el archivo: $e', error: true);
    } finally {
      if (mounted) setState(() => _trabajando = false);
    }
  }

  Future<void> _cerrarMes() async {
    final confirmado = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        title: Text('¿Cerrar ${Formato.periodo(widget.periodo)}?'),
        content: const Text(
          'Al cerrar el mes se congela la foto de cada hallazgo. El '
          'consolidado de este periodo quedará reproducible para siempre, y '
          'reabrirlo después exigirá un motivo registrado.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Cancelar'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Cerrar el mes'),
          ),
        ],
      ),
    );

    if (confirmado != true) return;

    setState(() => _trabajando = true);

    try {
      await ref.read(repositorioProvider).cerrarCorte(widget.periodo);
      ref.invalidate(cortesProvider);
      ref.invalidate(consolidadoProvider);
      _avisar('Corte de ${Formato.periodo(widget.periodo)} cerrado.');
    } on ErrorApi catch (e) {
      _avisar(e.mensaje, error: true);
    } finally {
      if (mounted) setState(() => _trabajando = false);
    }
  }

  void _avisar(String mensaje, {bool error = false}) {
    if (!mounted) return;

    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(mensaje),
        backgroundColor: error ? Paleta.critico : null,
        duration: Duration(seconds: error ? 6 : 4),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final detalle = ref.watch(detalleCorteProvider(widget.periodo));

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Bloque(
          child: detalle.when(
            loading: () => const SizedBox(
              height: 90,
              child: Center(child: CircularProgressIndicator()),
            ),
            error: (e, _) => Text('$e'),
            data: (corte) => Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Expanded(
                      child: Text(
                        Formato.periodo(corte.periodo),
                        style: const TextStyle(
                          fontSize: 18,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                    ),
                    const Pastilla(
                      texto: 'Abierto',
                      color: Paleta.alerta,
                      fondo: Color(0xFFF0E6CE),
                    ),
                  ],
                ),
                const SizedBox(height: 16),
                Row(
                  children: [
                    Expanded(
                      child: Cifra(
                        valor: Formato.numero(corte.reportados),
                        rotulo: 'Reportados',
                        color: Paleta.bien,
                      ),
                    ),
                    Expanded(
                      child: Cifra(
                        valor: Formato.numero(corte.pendientes),
                        rotulo: 'Sin reportar',
                        color: corte.pendientes > 0 ? Paleta.alerta : Paleta.apagado,
                      ),
                    ),
                    Expanded(
                      child: Cifra(
                        valor: Formato.numero(corte.hallazgos),
                        rotulo: 'Vigentes\nen el mes',
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 16),
                ClipRRect(
                  borderRadius: BorderRadius.circular(2),
                  child: LinearProgressIndicator(
                    value: corte.avanceDelMes,
                    minHeight: 8,
                    backgroundColor: Paleta.hundido,
                    valueColor: const AlwaysStoppedAnimation(Paleta.bien),
                  ),
                ),
                if (corte.porSede.isNotEmpty) ...[
                  const SizedBox(height: 18),
                  const Text(
                    'POR SEDE',
                    style: TextStyle(
                      fontSize: 10,
                      fontWeight: FontWeight.w700,
                      letterSpacing: 0.9,
                      color: Paleta.apagado,
                    ),
                  ),
                  const SizedBox(height: 8),
                  for (final entrada in corte.porSede.entries)
                    Padding(
                      padding: const EdgeInsets.symmetric(vertical: 3),
                      child: Row(
                        children: [
                          SizedBox(
                            width: 96,
                            child: Text(
                              entrada.key,
                              style: const TextStyle(fontSize: 12),
                            ),
                          ),
                          Expanded(
                            child: LinearProgressIndicator(
                              value: (entrada.value['total'] ?? 0) == 0
                                  ? 0
                                  : (entrada.value['reportados'] ?? 0) /
                                      entrada.value['total']!,
                              minHeight: 5,
                              backgroundColor: Paleta.hundido,
                              valueColor:
                                  const AlwaysStoppedAnimation(Paleta.sello),
                            ),
                          ),
                          const SizedBox(width: 10),
                          Text(
                            '${entrada.value['reportados']}/${entrada.value['total']}',
                            style: const TextStyle(
                              fontSize: 11.5,
                              color: Paleta.apagado,
                              fontFeatures: [FontFeature.tabularFigures()],
                            ),
                          ),
                        ],
                      ),
                    ),
                ],
              ],
            ),
          ),
        ),
        const SizedBox(height: 16),
        const RotuloSeccion('La matriz va y vuelve'),
        Row(
          children: [
            Expanded(
              child: OutlinedButton.icon(
                onPressed: _trabajando ? null : _descargarMatriz,
                icon: const Icon(Icons.download_outlined, size: 18),
                label: const Text('Descargar'),
                style: OutlinedButton.styleFrom(
                  padding: const EdgeInsets.symmetric(vertical: 15),
                  side: const BorderSide(color: Paleta.regla),
                  foregroundColor: Paleta.sello,
                ),
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: FilledButton.icon(
                onPressed: _trabajando ? null : _subirMatriz,
                icon: const Icon(Icons.upload_file_outlined, size: 18),
                label: const Text('Subir'),
              ),
            ),
          ],
        ),
        const SizedBox(height: 10),
        const Text(
          'Se descarga en el formato de siempre, se edita en Excel y se vuelve '
          'a subir. Cada fila viaja con su identificador, así que el regreso es '
          'exacto aunque cambien de orden.',
          style: TextStyle(fontSize: 12, color: Paleta.apagado, height: 1.45),
        ),
        const SizedBox(height: 20),
        OutlinedButton.icon(
          onPressed: _trabajando ? null : _cerrarMes,
          icon: const Icon(Icons.lock_outline, size: 18),
          label: const Text('Cerrar el mes'),
          style: OutlinedButton.styleFrom(
            padding: const EdgeInsets.symmetric(vertical: 15),
            side: const BorderSide(color: Paleta.regla),
            foregroundColor: Paleta.tinta2,
            minimumSize: const Size.fromHeight(0),
          ),
        ),
      ],
    );
  }
}

/// Qué pasó con el archivo que se subió. Los conflictos van primero: son lo
/// único que exige una decisión.
class _ResumenCarga extends StatelessWidget {
  const _ResumenCarga({required this.resumen});

  final Map<String, dynamic> resumen;

  int _n(String clave) => (resumen[clave] as num?)?.toInt() ?? 0;

  @override
  Widget build(BuildContext context) {
    final conflictos = _n('conflictos');
    final ausentes = _n('ausentes');
    final advertencias =
        ((resumen['advertencias'] as List?) ?? const []).cast<String>();

    return AlertDialog(
      title: const Text('Matriz procesada'),
      content: SingleChildScrollView(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: [
            _Linea('Filas leídas', _n('filas')),
            _Linea('Cambios aplicados', _n('aplicados'), color: Paleta.bien),
            _Linea('Sin cambio', _n('sin_cambio')),
            if (_n('nuevos') > 0) _Linea('Hallazgos nuevos', _n('nuevos')),
            if (conflictos > 0)
              _Linea('Conflictos', conflictos, color: Paleta.critico),
            if (ausentes > 0)
              _Linea('No volvieron en el archivo', ausentes, color: Paleta.alerta),
            if (conflictos > 0) ...[
              const SizedBox(height: 14),
              const Aviso(
                icono: Icons.merge_type,
                color: Paleta.critico,
                mensaje: 'Algunos hallazgos se tocaron en la app y en el Excel a '
                    'la vez. Se conservó lo que había en la app hasta que alguien '
                    'decida cuál versión vale.',
              ),
            ],
            if (ausentes > 0) ...[
              const SizedBox(height: 12),
              const Aviso(
                icono: Icons.help_outline,
                color: Paleta.alerta,
                mensaje: 'Los hallazgos que no venían en el archivo conservan su '
                    'estado. No se cierran por omisión: lo más probable es que la '
                    'fila se quedara sin digitar.',
              ),
            ],
            for (final advertencia in advertencias) ...[
              const SizedBox(height: 12),
              Aviso(mensaje: advertencia, color: Paleta.alerta),
            ],
          ],
        ),
      ),
      actions: [
        FilledButton(
          onPressed: () => Navigator.pop(context),
          child: const Text('Entendido'),
        ),
      ],
    );
  }
}

class _Linea extends StatelessWidget {
  const _Linea(this.rotulo, this.valor, {this.color});

  final String rotulo;
  final int valor;
  final Color? color;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 4),
        child: Row(
          children: [
            Expanded(
              child: Text(rotulo, style: const TextStyle(fontSize: 13.5)),
            ),
            Text(
              Formato.numero(valor),
              style: TextStyle(
                fontSize: 15,
                fontWeight: FontWeight.w700,
                color: color ?? Paleta.tinta,
                fontFeatures: const [FontFeature.tabularFigures()],
              ),
            ),
          ],
        ),
      );
}
