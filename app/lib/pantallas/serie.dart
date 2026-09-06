import 'package:fl_chart/fl_chart.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../datos/repositorios.dart';
import '../dominio/modelos.dart';
import '../nucleo/tema.dart';
import '../widgets/comunes.dart';

final serieProvider = FutureProvider.autoDispose<List<PuntoSerie>>(
  (ref) => ref.watch(repositorioProvider).serie(),
);

/// Evolución mes a mes.
///
/// Es lo que hoy exige abrir doce archivos, y la razón por la que vale la pena
/// congelar la foto de cada corte.
class PantallaSerie extends ConsumerWidget {
  const PantallaSerie({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final serie = ref.watch(serieProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Serie mensual')),
      body: serie.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (e, _) =>
            PantallaError(error: e, reintentar: () => ref.invalidate(serieProvider)),
        data: (puntos) {
          if (puntos.length < 2) {
            return SinContenido(
              icono: Icons.timeline_outlined,
              titulo: 'Todavía no hay serie',
              detalle: puntos.isEmpty
                  ? 'La serie aparece cuando se cierre el primer corte mensual.'
                  : 'Con un solo corte no hay evolución que mostrar. '
                      'Cierre el mes siguiente y la comparación aparecerá aquí.',
            );
          }

          return ListView(
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 32),
            children: [
              const RotuloSeccion('Avance acumulado'),
              Bloque(
                child: SizedBox(
                  height: 220,
                  child: _GraficoAvance(puntos: puntos),
                ),
              ),
              const SizedBox(height: 24),
              const RotuloSeccion('Mes a mes'),
              Bloque(
                padding: EdgeInsets.zero,
                child: Column(
                  children: [
                    for (var i = puntos.length - 1; i >= 0; i--) ...[
                      if (i < puntos.length - 1) const Divider(height: 1),
                      _FilaMes(
                        punto: puntos[i],
                        anterior: i > 0 ? puntos[i - 1] : null,
                      ),
                    ],
                  ],
                ),
              ),
            ],
          );
        },
      ),
    );
  }
}

class _GraficoAvance extends StatelessWidget {
  const _GraficoAvance({required this.puntos});

  final List<PuntoSerie> puntos;

  @override
  Widget build(BuildContext context) {
    final valores = [
      for (var i = 0; i < puntos.length; i++)
        FlSpot(i.toDouble(), (puntos[i].generales.pctAvance ?? 0) * 100),
    ];

    final maximo = valores.map((v) => v.y).reduce((a, b) => a > b ? a : b);
    // Techo redondeado hacia arriba para que la línea no toque el borde y las
    // etiquetas del eje nombren valores que el gráfico alcanza.
    final techo = ((maximo / 20).ceil() * 20).clamp(20, 100).toDouble();

    return LineChart(
      LineChartData(
        minY: 0,
        maxY: techo,
        gridData: FlGridData(
          drawVerticalLine: false,
          horizontalInterval: techo / 4,
          getDrawingHorizontalLine: (_) =>
              const FlLine(color: Paleta.regla, strokeWidth: 1),
        ),
        borderData: FlBorderData(
          show: true,
          border: const Border(
            left: BorderSide(color: Paleta.regla),
            bottom: BorderSide(color: Paleta.regla),
          ),
        ),
        titlesData: FlTitlesData(
          topTitles: const AxisTitles(sideTitles: SideTitles(showTitles: false)),
          rightTitles: const AxisTitles(sideTitles: SideTitles(showTitles: false)),
          leftTitles: AxisTitles(
            sideTitles: SideTitles(
              showTitles: true,
              reservedSize: 40,
              interval: techo / 4,
              getTitlesWidget: (valor, _) => Text(
                '${valor.round()} %',
                style: const TextStyle(fontSize: 10, color: Paleta.apagado),
              ),
            ),
          ),
          bottomTitles: AxisTitles(
            sideTitles: SideTitles(
              showTitles: true,
              reservedSize: 30,
              interval: 1,
              getTitlesWidget: (valor, _) {
                final i = valor.round();
                if (i < 0 || i >= puntos.length) return const SizedBox.shrink();

                return Padding(
                  padding: const EdgeInsets.only(top: 6),
                  child: Text(
                    puntos[i].periodo.substring(5),
                    style: const TextStyle(fontSize: 10, color: Paleta.apagado),
                  ),
                );
              },
            ),
          ),
        ),
        lineBarsData: [
          LineChartBarData(
            spots: valores,
            color: Paleta.bien,
            barWidth: 2.5,
            isCurved: false,
            dotData: FlDotData(
              show: true,
              getDotPainter: (spot, _, __, ___) => FlDotCirclePainter(
                radius: 3.5,
                color: Paleta.bien,
                strokeColor: Paleta.superficie,
                strokeWidth: 2,
              ),
            ),
            belowBarData: BarAreaData(
              show: true,
              color: Paleta.bien.withValues(alpha: 0.10),
            ),
          ),
        ],
        lineTouchData: LineTouchData(
          touchTooltipData: LineTouchTooltipData(
            getTooltipColor: (_) => Paleta.tinta,
            getTooltipItems: (spots) => spots
                .map((s) => LineTooltipItem(
                      '${puntos[s.x.round()].periodo}\n'
                      '${Formato.porcentaje(s.y / 100)} de avance',
                      const TextStyle(color: Colors.white, fontSize: 12),
                    ))
                .toList(),
          ),
        ),
      ),
    );
  }
}

class _FilaMes extends StatelessWidget {
  const _FilaMes({required this.punto, this.anterior});

  final PuntoSerie punto;
  final PuntoSerie? anterior;

  @override
  Widget build(BuildContext context) {
    final cambio = anterior == null
        ? null
        : punto.generales.cerrados - anterior!.generales.cerrados;

    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  Formato.periodo(punto.periodo),
                  style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w600),
                ),
              ),
              if (!punto.cerrado)
                const Pastilla(
                  texto: 'Abierto',
                  color: Paleta.alerta,
                  fondo: Color(0xFFF0E6CE),
                ),
              const SizedBox(width: 8),
              Text(
                Formato.porcentaje(punto.generales.pctAvance),
                style: const TextStyle(
                  fontSize: 15,
                  fontWeight: FontWeight.w700,
                  color: Paleta.bien,
                  fontFeatures: [FontFeature.tabularFigures()],
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),
          BarraEstados(generales: punto.generales, altura: 7),
          const SizedBox(height: 7),
          Row(
            children: [
              Text(
                '${Formato.numero(punto.generales.hallazgos)} hallazgos · '
                '${Formato.numero(punto.generales.cerrados)} cerrados',
                style: const TextStyle(fontSize: 11.5, color: Paleta.apagado),
              ),
              if (cambio != null && cambio != 0) ...[
                const SizedBox(width: 8),
                Text(
                  cambio > 0 ? '+$cambio en el mes' : '$cambio en el mes',
                  style: TextStyle(
                    fontSize: 11.5,
                    fontWeight: FontWeight.w600,
                    color: cambio > 0 ? Paleta.bien : Paleta.critico,
                  ),
                ),
              ],
            ],
          ),
        ],
      ),
    );
  }
}
