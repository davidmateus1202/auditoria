import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/date_symbol_data_local.dart';

import 'nucleo/tema.dart';
import 'pantallas/cargar_auditoria.dart';
import 'pantallas/consolidado.dart';
import 'pantallas/corte.dart';
import 'pantallas/hallazgos.dart';
import 'pantallas/inicio.dart';
import 'pantallas/ingreso.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  await initializeDateFormatting('es_CO');

  runApp(const ProviderScope(child: AplicacionAuditoria()));
}

class AplicacionAuditoria extends StatelessWidget {
  const AplicacionAuditoria({super.key});

  @override
  Widget build(BuildContext context) => MaterialApp(
        title: 'Auditoría SUH',
        debugShowCheckedModeBanner: false,
        theme: construirTema(),
        home: const Puerta(),
      );
}

/// Decide entre el ingreso y la aplicación según haya sesión.
class Puerta extends ConsumerWidget {
  const Puerta({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final sesion = ref.watch(sesionActivaProvider);

    return sesion.when(
      loading: () => const Scaffold(
        body: Center(child: CircularProgressIndicator()),
      ),
      error: (_, __) => const PantallaIngreso(),
      data: (usuario) =>
          usuario == null ? const PantallaIngreso() : const Armazon(),
    );
  }
}

/// Navegación principal. Cuatro destinos, en el orden en que se usan: se abre
/// el tablero, se consulta el detalle, se trabaja el mes y se entrega el
/// consolidado.
class Armazon extends ConsumerStatefulWidget {
  const Armazon({super.key});

  @override
  ConsumerState<Armazon> createState() => _ArmazonEstado();
}

class _ArmazonEstado extends ConsumerState<Armazon> {
  int _indice = 0;

  static const _paginas = [
    PantallaInicio(),
    PantallaHallazgos(),
    PantallaCorte(),
    PantallaConsolidado(),
  ];

  @override
  Widget build(BuildContext context) => Scaffold(
        body: IndexedStack(index: _indice, children: _paginas),
        drawer: const _Menu(),
        bottomNavigationBar: NavigationBar(
          selectedIndex: _indice,
          onDestinationSelected: (i) => setState(() => _indice = i),
          backgroundColor: Paleta.superficie,
          indicatorColor: Paleta.selloSuave,
          destinations: const [
            NavigationDestination(
              icon: Icon(Icons.dashboard_outlined),
              selectedIcon: Icon(Icons.dashboard, color: Paleta.sello),
              label: 'Inicio',
            ),
            NavigationDestination(
              icon: Icon(Icons.report_problem_outlined),
              selectedIcon: Icon(Icons.report_problem, color: Paleta.sello),
              label: 'Hallazgos',
            ),
            NavigationDestination(
              icon: Icon(Icons.calendar_month_outlined),
              selectedIcon: Icon(Icons.calendar_month, color: Paleta.sello),
              label: 'Corte',
            ),
            NavigationDestination(
              icon: Icon(Icons.table_chart_outlined),
              selectedIcon: Icon(Icons.table_chart, color: Paleta.sello),
              label: 'Consolidado',
            ),
          ],
        ),
      );
}

class _Menu extends ConsumerWidget {
  const _Menu();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final usuario = ref.watch(sesionActivaProvider).valueOrNull;

    return Drawer(
      backgroundColor: Paleta.superficie,
      child: SafeArea(
        child: Column(
          children: [
            Padding(
              padding: const EdgeInsets.all(20),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Text(
                    'AUDITORÍA SUH',
                    style: TextStyle(
                      fontSize: 11,
                      fontWeight: FontWeight.w700,
                      letterSpacing: 1.2,
                      color: Paleta.sello,
                    ),
                  ),
                  const SizedBox(height: 4),
                  Text(
                    usuario?.nombre ?? '',
                    style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w600),
                  ),
                  Text(
                    usuario?.email ?? '',
                    style: const TextStyle(fontSize: 12.5, color: Paleta.apagado),
                  ),
                ],
              ),
            ),
            const Divider(height: 1),
            ListTile(
              leading: const Icon(Icons.upload_file_outlined, size: 20),
              title: const Text('Cargar auditoría'),
              subtitle: const Text(
                'Autoevaluaciones SUH de las sedes',
                style: TextStyle(fontSize: 11.5),
              ),
              onTap: () {
                Navigator.pop(context);
                Navigator.of(context).push(
                  MaterialPageRoute(
                      builder: (_) => const PantallaCargarAuditoria()),
                );
              },
            ),
            const Divider(height: 1),
            const Spacer(),
            const Divider(height: 1),
            const Padding(
              padding: EdgeInsets.symmetric(horizontal: 20, vertical: 12),
              child: Text(
                'Resolución 3100 de 2019\nE.S.E. Municipal · Villavicencio, Meta',
                style: TextStyle(fontSize: 11.5, color: Paleta.apagado, height: 1.5),
              ),
            ),
            ListTile(
              leading: const Icon(Icons.logout, size: 20),
              title: const Text('Cerrar sesión'),
              onTap: () async {
                Navigator.pop(context);
                await ref.read(sesionActivaProvider.notifier).salir();
              },
            ),
            const SizedBox(height: 8),
          ],
        ),
      ),
    );
  }
}
