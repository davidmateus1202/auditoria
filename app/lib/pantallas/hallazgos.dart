import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../datos/repositorios.dart';
import '../dominio/enums.dart';
import '../dominio/modelos.dart';
import '../nucleo/tema.dart';
import '../widgets/comunes.dart';
import 'detalle_hallazgo.dart';
import 'inicio.dart' show estandaresProvider;

/// Estado de los filtros. Sede, estándar y sede+estándar son los tres cortes
/// que pidió el usuario: aquí son el mismo listado con distinta combinación.
class FiltroHallazgos {
  const FiltroHallazgos({
    this.sedeId,
    this.sedeCodigo,
    this.estandar,
    this.estado,
    this.buscar = '',
    this.soloVigentes = true,
  });

  final int? sedeId;
  final String? sedeCodigo;
  final String? estandar;
  final EstadoHallazgo? estado;
  final String buscar;
  final bool soloVigentes;

  int get activos => [
        sedeId != null,
        estandar != null,
        estado != null,
        buscar.isNotEmpty,
      ].where((v) => v).length;

  FiltroHallazgos copiar({
    Object? sedeId = _sin,
    Object? sedeCodigo = _sin,
    Object? estandar = _sin,
    Object? estado = _sin,
    String? buscar,
    bool? soloVigentes,
  }) =>
      FiltroHallazgos(
        sedeId: sedeId == _sin ? this.sedeId : sedeId as int?,
        sedeCodigo: sedeCodigo == _sin ? this.sedeCodigo : sedeCodigo as String?,
        estandar: estandar == _sin ? this.estandar : estandar as String?,
        estado: estado == _sin ? this.estado : estado as EstadoHallazgo?,
        buscar: buscar ?? this.buscar,
        soloVigentes: soloVigentes ?? this.soloVigentes,
      );

  static const _sin = Object();
}

final filtroProvider =
    StateProvider.autoDispose<FiltroHallazgos>((ref) => const FiltroHallazgos());

final sedesProvider = FutureProvider<List<Sede>>(
  (ref) => ref.watch(repositorioProvider).sedes(),
);

final listaHallazgosProvider =
    FutureProvider.autoDispose<({List<Hallazgo> datos, int total})>((ref) {
  final f = ref.watch(filtroProvider);

  return ref.watch(repositorioProvider).hallazgos(
        sedeId: f.sedeId,
        estandar: f.estandar,
        estado: f.estado,
        buscar: f.buscar,
        soloVigentes: f.soloVigentes,
      );
});

class PantallaHallazgos extends ConsumerStatefulWidget {
  const PantallaHallazgos({super.key, this.sedeInicial, this.estandarInicial});

  final String? sedeInicial;
  final String? estandarInicial;

  @override
  ConsumerState<PantallaHallazgos> createState() => _PantallaHallazgosEstado();
}

class _PantallaHallazgosEstado extends ConsumerState<PantallaHallazgos> {
  final _buscador = TextEditingController();

  @override
  void initState() {
    super.initState();

    // Llegar desde el tablero con un filtro ya puesto: la pantalla continúa
    // la pregunta que traía el usuario en vez de empezar de cero.
    if (widget.sedeInicial != null || widget.estandarInicial != null) {
      Future.microtask(() async {
        final sedes = await ref.read(sedesProvider.future);
        final sede = widget.sedeInicial == null
            ? null
            : sedes.where((s) => s.codigo == widget.sedeInicial).firstOrNull;

        if (!mounted) return;

        ref.read(filtroProvider.notifier).state = FiltroHallazgos(
          sedeId: sede?.id,
          sedeCodigo: sede?.codigo,
          estandar: widget.estandarInicial,
        );
      });
    }
  }

  @override
  void dispose() {
    _buscador.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final filtro = ref.watch(filtroProvider);
    final lista = ref.watch(listaHallazgosProvider);

    return Scaffold(
      appBar: AppBar(
        title: const Text('Hallazgos'),
        bottom: PreferredSize(
          preferredSize: const Size.fromHeight(112),
          child: _Filtros(buscador: _buscador),
        ),
      ),
      body: lista.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (e, _) => PantallaError(
          error: e,
          reintentar: () => ref.invalidate(listaHallazgosProvider),
        ),
        data: (resultado) {
          if (resultado.datos.isEmpty) {
            return SinContenido(
              icono: Icons.search_off,
              titulo: 'Ningún hallazgo con estos filtros',
              detalle: filtro.activos > 0
                  ? 'Pruebe quitando alguno de los ${filtro.activos} filtros activos.'
                  : 'Todavía no hay hallazgos cargados en el sistema.',
              accion: filtro.activos > 0
                  ? TextButton(
                      onPressed: () {
                        _buscador.clear();
                        ref.read(filtroProvider.notifier).state =
                            const FiltroHallazgos();
                      },
                      child: const Text('Quitar filtros'),
                    )
                  : null,
            );
          }

          return Column(
            children: [
              Container(
                width: double.infinity,
                padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 9),
                color: Paleta.superficie2,
                child: Text(
                  '${Formato.numero(resultado.total)} '
                  '${resultado.total == 1 ? "hallazgo" : "hallazgos"}'
                  '${resultado.total > resultado.datos.length ? " · mostrando ${resultado.datos.length}" : ""}',
                  style: const TextStyle(
                    fontSize: 12,
                    fontWeight: FontWeight.w600,
                    color: Paleta.tinta2,
                  ),
                ),
              ),
              Expanded(
                child: ListView.separated(
                  padding: const EdgeInsets.only(bottom: 24),
                  itemCount: resultado.datos.length,
                  separatorBuilder: (_, __) => const Divider(height: 1),
                  itemBuilder: (_, i) => _FilaHallazgo(hallazgo: resultado.datos[i]),
                ),
              ),
            ],
          );
        },
      ),
    );
  }
}

class _Filtros extends ConsumerWidget {
  const _Filtros({required this.buscador});

  final TextEditingController buscador;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final filtro = ref.watch(filtroProvider);
    final sedes = ref.watch(sedesProvider).valueOrNull ?? const [];
    final estandares = ref.watch(estandaresProvider).valueOrNull ?? const [];
    final notificador = ref.read(filtroProvider.notifier);

    return Container(
      color: Paleta.superficie,
      padding: const EdgeInsets.fromLTRB(12, 0, 12, 10),
      child: Column(
        children: [
          SizedBox(
            height: 44,
            child: TextField(
              controller: buscador,
              textInputAction: TextInputAction.search,
              onSubmitted: (v) =>
                  notificador.state = filtro.copiar(buscar: v.trim()),
              decoration: InputDecoration(
                hintText: 'Buscar en el texto del hallazgo',
                prefixIcon: const Icon(Icons.search, size: 19),
                contentPadding: EdgeInsets.zero,
                suffixIcon: filtro.buscar.isEmpty
                    ? null
                    : IconButton(
                        icon: const Icon(Icons.close, size: 18),
                        tooltip: 'Limpiar',
                        onPressed: () {
                          buscador.clear();
                          notificador.state = filtro.copiar(buscar: '');
                        },
                      ),
              ),
            ),
          ),
          const SizedBox(height: 9),
          SizedBox(
            height: 34,
            child: ListView(
              scrollDirection: Axis.horizontal,
              children: [
                _Selector<Sede>(
                  etiqueta: 'Sede',
                  valorActual: filtro.sedeCodigo,
                  opciones: sedes,
                  nombre: (s) => s.nombre,
                  onSelect: (s) => notificador.state = filtro.copiar(
                    sedeId: s?.id,
                    sedeCodigo: s?.codigo,
                  ),
                ),
                const SizedBox(width: 8),
                _Selector<Estandar>(
                  etiqueta: 'Estándar',
                  valorActual: filtro.estandar == null
                      ? null
                      : estandares
                          .where((e) => e.codigo == filtro.estandar)
                          .firstOrNull
                          ?.nombre,
                  opciones: estandares,
                  nombre: (e) => e.nombre,
                  onSelect: (e) =>
                      notificador.state = filtro.copiar(estandar: e?.codigo),
                ),
                const SizedBox(width: 8),
                _Selector<EstadoHallazgo>(
                  etiqueta: 'Estado',
                  valorActual: filtro.estado?.etiqueta,
                  opciones: EstadoHallazgo.values,
                  nombre: (e) => e.etiqueta,
                  onSelect: (e) => notificador.state = filtro.copiar(estado: e),
                ),
                const SizedBox(width: 8),
                FilterChip(
                  label: const Text('Solo vigentes'),
                  selected: filtro.soloVigentes,
                  onSelected: (v) =>
                      notificador.state = filtro.copiar(soloVigentes: v),
                  selectedColor: Paleta.selloSuave,
                  checkmarkColor: Paleta.sello,
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _Selector<T> extends StatelessWidget {
  const _Selector({
    required this.etiqueta,
    required this.valorActual,
    required this.opciones,
    required this.nombre,
    required this.onSelect,
  });

  final String etiqueta;
  final String? valorActual;
  final List<T> opciones;
  final String Function(T) nombre;
  final void Function(T?) onSelect;

  @override
  Widget build(BuildContext context) {
    final activo = valorActual != null;

    return ActionChip(
      avatar: Icon(
        activo ? Icons.check : Icons.expand_more,
        size: 16,
        color: activo ? Paleta.sello : Paleta.apagado,
      ),
      label: Text(activo ? valorActual! : etiqueta),
      backgroundColor: activo ? Paleta.selloSuave : Paleta.superficie,
      side: BorderSide(color: activo ? Paleta.sello : Paleta.regla),
      labelStyle: TextStyle(
        fontSize: 13,
        color: activo ? Paleta.sello : Paleta.tinta2,
        fontWeight: activo ? FontWeight.w600 : FontWeight.w400,
      ),
      onPressed: () async {
        final elegido = await showModalBottomSheet<Object?>(
          context: context,
          showDragHandle: true,
          builder: (_) => SafeArea(
            child: ListView(
              shrinkWrap: true,
              children: [
                Padding(
                  padding: const EdgeInsets.fromLTRB(20, 0, 20, 8),
                  child: Text(
                    etiqueta,
                    style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w600),
                  ),
                ),
                if (activo)
                  ListTile(
                    leading: const Icon(Icons.close, size: 20),
                    title: const Text('Quitar este filtro'),
                    onTap: () => Navigator.pop(context, _quitar),
                  ),
                for (final opcion in opciones)
                  ListTile(
                    title: Text(nombre(opcion)),
                    trailing: nombre(opcion) == valorActual
                        ? const Icon(Icons.check, color: Paleta.sello, size: 20)
                        : null,
                    onTap: () => Navigator.pop(context, opcion),
                  ),
              ],
            ),
          ),
        );

        if (elegido == _quitar) {
          onSelect(null);
        } else if (elegido != null) {
          onSelect(elegido as T);
        }
      },
    );
  }

  static const _quitar = 'quitar';
}

class _FilaHallazgo extends StatelessWidget {
  const _FilaHallazgo({required this.hallazgo});

  final Hallazgo hallazgo;

  @override
  Widget build(BuildContext context) => InkWell(
        onTap: () => Navigator.of(context).push(
          MaterialPageRoute(
            builder: (_) => PantallaDetalleHallazgo(hallazgo: hallazgo),
          ),
        ),
        child: Container(
          color: Paleta.superficie,
          padding: const EdgeInsets.fromLTRB(16, 13, 12, 13),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              // Franja de severidad: el estado se lee antes que el texto.
              Container(
                width: 3,
                height: 46,
                margin: const EdgeInsets.only(right: 12, top: 2),
                color: hallazgo.estado.color(context),
              ),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Expanded(
                          child: Text(
                            '${hallazgo.sedeNombre} · ${hallazgo.nombreEstandar}',
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: const TextStyle(
                              fontSize: 11.5,
                              fontWeight: FontWeight.w600,
                              color: Paleta.apagado,
                              letterSpacing: 0.2,
                            ),
                          ),
                        ),
                        Pastilla.estado(context, hallazgo.estado),
                      ],
                    ),
                    const SizedBox(height: 5),
                    Text(
                      hallazgo.descripcion,
                      maxLines: 3,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(fontSize: 13.5, height: 1.4),
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      );
}
