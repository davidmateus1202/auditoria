import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../datos/repositorios.dart';
import '../dominio/enums.dart';
import '../dominio/modelos.dart';
import '../nucleo/descargas.dart';
import '../nucleo/tema.dart';
import '../widgets/comunes.dart';
import 'inicio.dart' show consolidadoProvider, estandaresProvider;
import 'serie.dart';

/// El consolidado, con los tres cortes que pidió el usuario.
///
/// Son el mismo dato con distinta agrupación —el backend lo entrega desde un
/// solo endpoint— así que lo único que cambia entre pestañas es cómo se dibuja.
/// Eso garantiza que el Excel exportado y lo que se ve en pantalla no puedan
/// divergir.
class PantallaConsolidado extends ConsumerStatefulWidget {
  const PantallaConsolidado({super.key});

  @override
  ConsumerState<PantallaConsolidado> createState() => _PantallaConsolidadoEstado();
}

class _PantallaConsolidadoEstado extends ConsumerState<PantallaConsolidado>
    with SingleTickerProviderStateMixin {
  late final _pestanas = TabController(length: 3, vsync: this);
  bool _exportando = false;

  @override
  void dispose() {
    _pestanas.dispose();
    super.dispose();
  }

  Future<void> _exportar(String periodo) async {
    setState(() => _exportando = true);

    try {
      final bytes =
          await ref.read(repositorioProvider).exportarConsolidado(periodo: periodo);

      if (!mounted) return;

      final guardado = await guardarArchivo(
        nombre: 'consolidado-$periodo.xlsx',
        bytes: bytes,
        extensiones: const ['xlsx'],
      );

      if (!mounted || !guardado) return;

      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            'Consolidado de ${Formato.periodo(periodo)} descargado '
            '(${(bytes.length / 1024).round()} KB).',
          ),
        ),
      );
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text('No se pudo exportar: $e')));
      }
    } finally {
      if (mounted) setState(() => _exportando = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final consolidado = ref.watch(consolidadoProvider);

    return Scaffold(
      appBar: AppBar(
        title: const Text('Consolidado'),
        actions: [
          IconButton(
            icon: const Icon(Icons.show_chart),
            tooltip: 'Serie mensual',
            onPressed: () => Navigator.of(context).push(
              MaterialPageRoute(builder: (_) => const PantallaSerie()),
            ),
          ),
        ],
        bottom: TabBar(
          controller: _pestanas,
          tabs: [
            for (final a in AgrupacionConsolidado.values) Tab(text: a.etiqueta),
          ],
        ),
      ),
      body: consolidado.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (e, _) => PantallaError(
          error: e,
          reintentar: () => ref.invalidate(consolidadoProvider),
        ),
        data: (datos) => Column(
          children: [
            _Cabecera(consolidado: datos),
            Expanded(
              child: TabBarView(
                controller: _pestanas,
                children: [
                  _VistaPorSede(consolidado: datos),
                  _VistaPorEstandar(consolidado: datos),
                  _VistaMatriz(consolidado: datos),
                ],
              ),
            ),
          ],
        ),
      ),
      floatingActionButton: consolidado.valueOrNull == null
          ? null
          : FloatingActionButton.extended(
              onPressed: _exportando
                  ? null
                  : () => _exportar(consolidado.value!.periodo),
              backgroundColor: Paleta.sello,
              foregroundColor: Colors.white,
              icon: _exportando
                  ? const SizedBox(
                      height: 16,
                      width: 16,
                      child: CircularProgressIndicator(
                          strokeWidth: 2, color: Colors.white),
                    )
                  : const Icon(Icons.download_outlined),
              label: Text(_exportando ? 'Generando…' : 'Excel'),
            ),
    );
  }
}

/// Siempre visible: de qué mes son las cifras. El archivo que usan hoy no está
/// fechado, y por eso nadie puede comparar dos meses sin equivocarse.
class _Cabecera extends StatelessWidget {
  const _Cabecera({required this.consolidado});

  final Consolidado consolidado;

  @override
  Widget build(BuildContext context) => Container(
        width: double.infinity,
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 11),
        color: consolidado.corteCerrado ? Paleta.superficie2 : const Color(0xFFF0E6CE),
        child: Row(
          children: [
            Icon(
              consolidado.corteCerrado
                  ? Icons.lock_outline
                  : Icons.edit_calendar_outlined,
              size: 15,
              color: consolidado.corteCerrado ? Paleta.apagado : Paleta.alerta,
            ),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                'Corte de ${Formato.periodo(consolidado.periodo)}'
                '${consolidado.corteCerrado ? "" : " · abierto, las cifras pueden cambiar"}',
                style: TextStyle(
                  fontSize: 12.5,
                  fontWeight: FontWeight.w600,
                  color: consolidado.corteCerrado ? Paleta.tinta2 : Paleta.alerta,
                ),
              ),
            ),
            Text(
              '${Formato.numero(consolidado.generales.hallazgos)} hallazgos',
              style: const TextStyle(
                fontSize: 12.5,
                fontWeight: FontWeight.w700,
                fontFeatures: [FontFeature.tabularFigures()],
              ),
            ),
          ],
        ),
      );
}

class _VistaPorSede extends StatelessWidget {
  const _VistaPorSede({required this.consolidado});

  final Consolidado consolidado;

  @override
  Widget build(BuildContext context) => ListView(
        padding: const EdgeInsets.fromLTRB(16, 16, 16, 90),
        children: [
          for (final fila in consolidado.porSede) ...[
            Bloque(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Expanded(
                        child: Text(
                          fila.nombre,
                          style: const TextStyle(
                            fontSize: 15,
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                      ),
                      Text(
                        Formato.numero(fila.generales.hallazgos),
                        style: const TextStyle(
                          fontSize: 19,
                          fontWeight: FontWeight.w700,
                          fontFeatures: [FontFeature.tabularFigures()],
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 10),
                  BarraEstados(generales: fila.generales, altura: 8),
                  const SizedBox(height: 10),
                  Row(
                    children: [
                      _Metrica(
                        rotulo: 'Avance',
                        valor: Formato.porcentaje(fila.generales.pctAvance),
                        color: Paleta.bien,
                      ),
                      _Metrica(
                        rotulo: 'Con gestión',
                        valor: Formato.porcentaje(fila.generales.pctConGestion),
                        color: Paleta.alerta,
                      ),
                      if (fila.mesAntiguoMeses > 0)
                        _Metrica(
                          rotulo: 'Más antiguo',
                          valor: '${fila.mesAntiguoMeses} m',
                          color: Paleta.tinta2,
                        ),
                    ],
                  ),
                ],
              ),
            ),
            const SizedBox(height: 10),
          ],
        ],
      );
}

class _Metrica extends StatelessWidget {
  const _Metrica({required this.rotulo, required this.valor, required this.color});

  final String rotulo;
  final String valor;
  final Color color;

  @override
  Widget build(BuildContext context) => Expanded(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              rotulo.toUpperCase(),
              style: const TextStyle(
                fontSize: 9.5,
                fontWeight: FontWeight.w700,
                letterSpacing: 0.7,
                color: Paleta.apagado,
              ),
            ),
            const SizedBox(height: 2),
            Text(
              valor,
              style: TextStyle(
                fontSize: 14,
                fontWeight: FontWeight.w700,
                color: color,
                fontFeatures: const [FontFeature.tabularFigures()],
              ),
            ),
          ],
        ),
      );
}

class _VistaPorEstandar extends ConsumerWidget {
  const _VistaPorEstandar({required this.consolidado});

  final Consolidado consolidado;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (consolidado.porEstandar.isEmpty) {
      return const SinContenido(
        titulo: 'Sin datos en este corte',
        detalle: 'No hay hallazgos registrados para el periodo seleccionado.',
      );
    }

    final maximo = consolidado.porEstandar.first.generales.hallazgos;

    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 90),
      children: [
        Bloque(
          child: Column(
            children: [
              for (final fila in consolidado.porEstandar)
                BarraHorizontal(
                  rotulo: fila.nombre,
                  valor: fila.generales.hallazgos,
                  maximo: maximo,
                  porcentaje: fila.pctDelTotal,
                ),
            ],
          ),
        ),
        const SizedBox(height: 16),
        const RotuloSeccion('Estado por estándar'),
        for (final fila in consolidado.porEstandar) ...[
          Bloque(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Expanded(
                      child: Text(
                        fila.nombre,
                        style: const TextStyle(
                          fontSize: 14,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                    ),
                    Text(
                      Formato.numero(fila.generales.hallazgos),
                      style: const TextStyle(
                        fontSize: 16,
                        fontWeight: FontWeight.w700,
                        fontFeatures: [FontFeature.tabularFigures()],
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 8),
                BarraEstados(generales: fila.generales, altura: 7),
                const SizedBox(height: 8),
                LeyendaEstados(generales: fila.generales),
              ],
            ),
          ),
          const SizedBox(height: 10),
        ],
      ],
    );
  }
}

/// La matriz sede × estándar. Se desplaza en horizontal dentro de su propio
/// contenedor: la pantalla nunca se mueve de lado.
class _VistaMatriz extends ConsumerWidget {
  const _VistaMatriz({required this.consolidado});

  final Consolidado consolidado;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final estandares = ref.watch(estandaresProvider).valueOrNull ?? const [];

    if (estandares.isEmpty || consolidado.sedePorEstandar.isEmpty) {
      return const Center(child: CircularProgressIndicator());
    }

    // Solo las columnas que tienen algo: una matriz llena de ceros no informa.
    final columnas = estandares
        .where((e) => consolidado.sedePorEstandar
            .any((f) => (f.estandares[e.codigo] ?? 0) > 0))
        .toList();

    final totales = {
      for (final e in columnas)
        e.codigo: consolidado.sedePorEstandar
            .fold<int>(0, (a, f) => a + (f.estandares[e.codigo] ?? 0)),
    };

    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 90),
      children: [
        Bloque(
          padding: EdgeInsets.zero,
          child: SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: DataTable(
              headingRowHeight: 48,
              dataRowMinHeight: 44,
              dataRowMaxHeight: 44,
              horizontalMargin: 14,
              columnSpacing: 18,
              headingRowColor: WidgetStatePropertyAll(Paleta.superficie2),
              columns: [
                const DataColumn(
                  label: Text('Centro de salud',
                      style: TextStyle(fontSize: 11.5, fontWeight: FontWeight.w700)),
                ),
                for (final e in columnas)
                  DataColumn(
                    numeric: true,
                    // El nombre completo no cabe en una columna de tabla en
                    // móvil, y cortado a la mitad no dice nada. Se abrevia y el
                    // nombre entero queda a un toque de distancia.
                    tooltip: e.nombre,
                    label: Text(
                      _abreviar(e.nombre),
                      style: const TextStyle(
                        fontSize: 11,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                  ),
                const DataColumn(
                  numeric: true,
                  label: Text('Total',
                      style: TextStyle(fontSize: 11.5, fontWeight: FontWeight.w700)),
                ),
              ],
              rows: [
                for (final fila in consolidado.sedePorEstandar)
                  DataRow(
                    cells: [
                      DataCell(Text(
                        fila.nombre,
                        style: const TextStyle(fontSize: 12.5),
                      )),
                      for (final e in columnas)
                        DataCell(_Celda(
                          valor: fila.estandares[e.codigo] ?? 0,
                          dominante: fila.estandarDominante == e.codigo,
                        )),
                      DataCell(Text(
                        Formato.numero(fila.total),
                        style: const TextStyle(
                          fontSize: 13,
                          fontWeight: FontWeight.w700,
                          fontFeatures: [FontFeature.tabularFigures()],
                        ),
                      )),
                    ],
                  ),
                DataRow(
                  color: WidgetStatePropertyAll(Paleta.hundido),
                  cells: [
                    const DataCell(Text('TOTAL',
                        style: TextStyle(fontSize: 12, fontWeight: FontWeight.w700))),
                    for (final e in columnas)
                      DataCell(Text(
                        Formato.numero(totales[e.codigo] ?? 0),
                        style: const TextStyle(
                          fontSize: 13,
                          fontWeight: FontWeight.w700,
                          fontFeatures: [FontFeature.tabularFigures()],
                        ),
                      )),
                    DataCell(Text(
                      Formato.numero(consolidado.generales.hallazgos),
                      style: const TextStyle(
                        fontSize: 13,
                        fontWeight: FontWeight.w700,
                        fontFeatures: [FontFeature.tabularFigures()],
                      ),
                    )),
                  ],
                ),
              ],
            ),
          ),
        ),
        const SizedBox(height: 12),
        const Aviso(
          icono: Icons.lightbulb_outline,
          mensaje: 'La celda resaltada de cada fila es el estándar donde esa '
              'sede concentra más hallazgos.',
        ),
      ],
    );
  }
}

/// Abreviatura corta y reconocible del estándar para los encabezados de tabla.
String _abreviar(String nombre) {
  const abreviaturas = {
    'Talento humano': 'Talento',
    'Infraestructura': 'Infra.',
    'Dotación': 'Dotación',
    'Medicamentos, dispositivos médicos e insumos': 'Medicam.',
    'Procesos prioritarios': 'Procesos',
    'Historia clínica y registros': 'H. clínica',
    'Interdependencia': 'Interdep.',
    'Servicios habilitados no prestados': 'No prest.',
  };

  return abreviaturas[nombre] ??
      (nombre.length > 10 ? '${nombre.substring(0, 9)}.' : nombre);
}

class _Celda extends StatelessWidget {
  const _Celda({required this.valor, required this.dominante});

  final int valor;
  final bool dominante;

  @override
  Widget build(BuildContext context) {
    if (valor == 0) {
      return const Text('—', style: TextStyle(fontSize: 13, color: Paleta.regla));
    }

    return Container(
      padding: dominante
          ? const EdgeInsets.symmetric(horizontal: 7, vertical: 3)
          : EdgeInsets.zero,
      decoration: dominante
          ? BoxDecoration(
              color: Paleta.selloSuave,
              borderRadius: BorderRadius.circular(3),
            )
          : null,
      child: Text(
        Formato.numero(valor),
        style: TextStyle(
          fontSize: 13,
          fontWeight: dominante ? FontWeight.w700 : FontWeight.w400,
          color: dominante ? Paleta.sello : Paleta.tinta,
          fontFeatures: const [FontFeature.tabularFigures()],
        ),
      ),
    );
  }
}
