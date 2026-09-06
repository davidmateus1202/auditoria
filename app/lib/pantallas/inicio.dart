import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../datos/repositorios.dart';
import '../dominio/modelos.dart';
import '../nucleo/tema.dart';
import '../widgets/comunes.dart';
import 'hallazgos.dart';

final consolidadoProvider =
    FutureProvider.autoDispose<Consolidado>((ref) async {
  return ref.watch(repositorioProvider).consolidado();
});

final estandaresProvider = FutureProvider<List<Estandar>>(
  (ref) => ref.watch(repositorioProvider).estandares(),
);

/// Tablero del municipio.
///
/// Abre mostrando lo que hay que decidir: cuántos hallazgos siguen vigentes,
/// dónde se concentran y qué sedes tienen más abiertos. Las cifras del corte
/// van arriba porque son la respuesta a la pregunta que trae quien abre la app.
class PantallaInicio extends ConsumerWidget {
  const PantallaInicio({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final consolidado = ref.watch(consolidadoProvider);

    return Scaffold(
      appBar: AppBar(
        title: const Text('Auditoría SUH'),
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh),
            tooltip: 'Actualizar',
            onPressed: () => ref.invalidate(consolidadoProvider),
          ),
        ],
      ),
      body: consolidado.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (e, _) => PantallaError(
          error: e,
          reintentar: () => ref.invalidate(consolidadoProvider),
        ),
        data: (datos) => RefreshIndicator(
          onRefresh: () async => ref.invalidate(consolidadoProvider),
          child: _Contenido(consolidado: datos),
        ),
      ),
    );
  }
}

class _Contenido extends ConsumerWidget {
  const _Contenido({required this.consolidado});

  final Consolidado consolidado;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final g = consolidado.generales;

    if (g.hallazgos == 0) {
      return const SinContenido(
        icono: Icons.folder_off_outlined,
        titulo: 'Todavía no hay hallazgos',
        detalle: 'Cargue la matriz de seguimiento para abrir el primer corte '
            'y empezar a ver las cifras del municipio.',
      );
    }

    final estandares = ref.watch(estandaresProvider).valueOrNull ?? const [];
    final nombres = {for (final e in estandares) e.codigo: e.nombre};

    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 32),
      children: [
        _CabeceraCorte(consolidado: consolidado),
        const SizedBox(height: 20),
        Bloque(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Expanded(
                    child: Cifra(
                      valor: Formato.numero(g.hallazgos),
                      rotulo: 'Hallazgos\ndel corte',
                    ),
                  ),
                  Expanded(
                    child: Cifra(
                      valor: Formato.porcentaje(g.pctAvance),
                      rotulo: 'Avance\ncerrados',
                      color: Paleta.bien,
                    ),
                  ),
                  Expanded(
                    child: Cifra(
                      valor: Formato.numero(g.abiertos),
                      rotulo: 'Abiertos\nsin gestión',
                      color: Paleta.critico,
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 20),
              BarraEstados(generales: g),
              const SizedBox(height: 12),
              LeyendaEstados(generales: g),
            ],
          ),
        ),
        const SizedBox(height: 24),
        const RotuloSeccion('Dónde se concentran'),
        Bloque(
          child: Column(
            children: [
              for (final fila in consolidado.porEstandar)
                BarraHorizontal(
                  rotulo: nombres[fila.clave] ?? fila.nombre,
                  valor: fila.generales.hallazgos,
                  maximo: consolidado.porEstandar.first.generales.hallazgos,
                  porcentaje: fila.pctDelTotal,
                  onTap: () => Navigator.of(context).push(
                    MaterialPageRoute(
                      builder: (_) => PantallaHallazgos(estandarInicial: fila.clave),
                    ),
                  ),
                ),
            ],
          ),
        ),
        const SizedBox(height: 10),
        _Interpretacion(consolidado: consolidado),
        const SizedBox(height: 24),
        RotuloSeccion(
          'Sedes con más abiertos',
          accion: TextButton(
            onPressed: () => Navigator.of(context).push(
              MaterialPageRoute(builder: (_) => const PantallaHallazgos()),
            ),
            child: const Text('Ver todos'),
          ),
        ),
        Bloque(
          padding: EdgeInsets.zero,
          child: Column(
            children: [
              for (var i = 0; i < consolidado.porSede.length; i++) ...[
                if (i > 0) const Divider(height: 1),
                _FilaSede(fila: consolidado.porSede[i]),
              ],
            ],
          ),
        ),
      ],
    );
  }
}

class _CabeceraCorte extends StatelessWidget {
  const _CabeceraCorte({required this.consolidado});

  final Consolidado consolidado;

  @override
  Widget build(BuildContext context) {
    if (!consolidado.corteCerrado) {
      return Aviso(
        icono: Icons.edit_calendar_outlined,
        color: Paleta.alerta,
        mensaje: 'Corte de ${Formato.periodo(consolidado.periodo)} todavía '
            'abierto: las cifras pueden cambiar hasta que se cierre el mes.',
      );
    }

    return Row(
      children: [
        const Icon(Icons.event_available_outlined, size: 17, color: Paleta.apagado),
        const SizedBox(width: 8),
        Text(
          'Corte de ${Formato.periodo(consolidado.periodo)}',
          style: const TextStyle(
            fontSize: 13,
            color: Paleta.tinta2,
            fontWeight: FontWeight.w600,
          ),
        ),
      ],
    );
  }
}

/// Una línea que dice qué significan las barras de arriba. El dato solo sirve
/// si alguien puede actuar sobre él.
class _Interpretacion extends StatelessWidget {
  const _Interpretacion({required this.consolidado});

  final Consolidado consolidado;

  @override
  Widget build(BuildContext context) {
    if (consolidado.porEstandar.length < 2) return const SizedBox.shrink();

    final dosPrimeros = consolidado.porEstandar.take(2).toList();
    final suma = dosPrimeros
        .map((f) => f.pctDelTotal ?? 0)
        .fold<double>(0, (a, b) => a + b);

    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 2),
      child: Text(
        '${Formato.porcentaje(suma, decimales: 0)} de los hallazgos son de '
        '${dosPrimeros[0].nombre.toLowerCase()} y ${dosPrimeros[1].nombre.toLowerCase()}.',
        style: const TextStyle(fontSize: 12.5, color: Paleta.apagado, height: 1.4),
      ),
    );
  }
}

class _FilaSede extends StatelessWidget {
  const _FilaSede({required this.fila});

  final FilaConsolidado fila;

  @override
  Widget build(BuildContext context) => InkWell(
        onTap: () => Navigator.of(context).push(
          MaterialPageRoute(
            builder: (_) => PantallaHallazgos(sedeInicial: fila.clave),
          ),
        ),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
          child: Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      fila.nombre,
                      style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w600),
                    ),
                    const SizedBox(height: 6),
                    BarraEstados(generales: fila.generales, altura: 6),
                  ],
                ),
              ),
              const SizedBox(width: 14),
              Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  Text(
                    Formato.numero(fila.generales.hallazgos),
                    style: const TextStyle(
                      fontSize: 17,
                      fontWeight: FontWeight.w700,
                      fontFeatures: [FontFeature.tabularFigures()],
                    ),
                  ),
                  Text(
                    '${Formato.porcentaje(fila.generales.pctAvance, decimales: 0)} cerrado',
                    style: const TextStyle(fontSize: 11, color: Paleta.apagado),
                  ),
                ],
              ),
              const Icon(Icons.chevron_right, color: Paleta.regla),
            ],
          ),
        ),
      );
}
