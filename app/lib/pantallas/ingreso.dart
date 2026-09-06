import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../datos/repositorios.dart';
import '../dominio/modelos.dart';
import '../nucleo/api.dart';
import '../nucleo/tema.dart';

/// Sesión activa. `null` significa que hay que ingresar.
class ControladorSesion extends AsyncNotifier<Usuario?> {
  @override
  Future<Usuario?> build() async {
    final token = await ref.read(sesionProvider).token();

    if (token == null) return null;

    try {
      return await ref.read(repositorioProvider).yo();
    } on ErrorApi {
      // Un token viejo no debería dejar la app atascada en una pantalla de error.
      await ref.read(sesionProvider).borrar();
      return null;
    }
  }

  Future<void> ingresar(String email, String clave) async {
    state = const AsyncLoading();
    state = await AsyncValue.guard(
      () => ref.read(repositorioProvider).ingresar(email, clave),
    );
  }

  Future<void> salir() async {
    await ref.read(repositorioProvider).salir();
    state = const AsyncData(null);
  }
}

final sesionActivaProvider =
    AsyncNotifierProvider<ControladorSesion, Usuario?>(ControladorSesion.new);

class PantallaIngreso extends ConsumerStatefulWidget {
  const PantallaIngreso({super.key});

  @override
  ConsumerState<PantallaIngreso> createState() => _PantallaIngresoEstado();
}

class _PantallaIngresoEstado extends ConsumerState<PantallaIngreso> {
  final _formulario = GlobalKey<FormState>();
  final _email = TextEditingController();
  final _clave = TextEditingController();
  bool _ocultarClave = true;
  bool _enviando = false;
  String? _error;

  @override
  void dispose() {
    _email.dispose();
    _clave.dispose();
    super.dispose();
  }

  Future<void> _ingresar() async {
    if (!_formulario.currentState!.validate()) return;

    setState(() {
      _enviando = true;
      _error = null;
    });

    try {
      await ref
          .read(sesionActivaProvider.notifier)
          .ingresar(_email.text.trim(), _clave.text);
    } on ErrorApi catch (e) {
      if (mounted) setState(() => _error = e.mensaje);
    } catch (e) {
      if (mounted) setState(() => _error = '$e');
    } finally {
      if (mounted) setState(() => _enviando = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        body: SafeArea(
          child: Center(
            child: SingleChildScrollView(
              padding: const EdgeInsets.all(24),
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 400),
                child: Form(
                  key: _formulario,
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      // Membrete: la app es un documento controlado más.
                      Container(
                        padding: const EdgeInsets.all(14),
                        decoration: BoxDecoration(
                          border: Border.all(color: Paleta.regla),
                          borderRadius: BorderRadius.circular(3),
                          color: Paleta.superficie,
                        ),
                        child: const Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              'E.S.E. MUNICIPAL · VILLAVICENCIO, META',
                              style: TextStyle(
                                fontSize: 10,
                                fontWeight: FontWeight.w700,
                                letterSpacing: 1,
                                color: Paleta.apagado,
                              ),
                            ),
                            SizedBox(height: 4),
                            Text(
                              'Resolución 3100 de 2019',
                              style: TextStyle(fontSize: 12, color: Paleta.tinta2),
                            ),
                          ],
                        ),
                      ),
                      const SizedBox(height: 28),
                      const Text(
                        'Auditoría SUH',
                        style: TextStyle(
                          fontSize: 34,
                          fontWeight: FontWeight.w700,
                          letterSpacing: -1,
                          height: 1.05,
                        ),
                      ),
                      const SizedBox(height: 6),
                      const Text(
                        'Sistema Único de Habilitación · puestos de salud municipales',
                        style: TextStyle(fontSize: 14, color: Paleta.tinta2, height: 1.4),
                      ),
                      const SizedBox(height: 32),
                      TextFormField(
                        controller: _email,
                        keyboardType: TextInputType.emailAddress,
                        autofillHints: const [AutofillHints.email],
                        decoration: const InputDecoration(
                          labelText: 'Correo',
                          prefixIcon: Icon(Icons.alternate_email, size: 20),
                        ),
                        validator: (v) => (v == null || !v.contains('@'))
                            ? 'Escriba un correo válido'
                            : null,
                      ),
                      const SizedBox(height: 14),
                      TextFormField(
                        controller: _clave,
                        obscureText: _ocultarClave,
                        autofillHints: const [AutofillHints.password],
                        onFieldSubmitted: (_) => _ingresar(),
                        decoration: InputDecoration(
                          labelText: 'Contraseña',
                          prefixIcon: const Icon(Icons.lock_outline, size: 20),
                          suffixIcon: IconButton(
                            icon: Icon(
                              _ocultarClave ? Icons.visibility_outlined : Icons.visibility_off_outlined,
                              size: 20,
                            ),
                            tooltip: _ocultarClave ? 'Mostrar' : 'Ocultar',
                            onPressed: () =>
                                setState(() => _ocultarClave = !_ocultarClave),
                          ),
                        ),
                        validator: (v) =>
                            (v == null || v.isEmpty) ? 'Escriba su contraseña' : null,
                      ),
                      if (_error != null) ...[
                        const SizedBox(height: 16),
                        Container(
                          padding: const EdgeInsets.all(12),
                          decoration: BoxDecoration(
                            color: Paleta.critico.withValues(alpha: 0.08),
                            border: const Border(
                              left: BorderSide(color: Paleta.critico, width: 3),
                            ),
                          ),
                          child: Text(
                            _error!,
                            style: const TextStyle(
                              fontSize: 13,
                              color: Paleta.critico,
                              height: 1.4,
                            ),
                          ),
                        ),
                      ],
                      const SizedBox(height: 24),
                      FilledButton(
                        onPressed: _enviando ? null : _ingresar,
                        child: _enviando
                            ? const SizedBox(
                                height: 18,
                                width: 18,
                                child: CircularProgressIndicator(
                                  strokeWidth: 2,
                                  color: Colors.white,
                                ),
                              )
                            : const Text('Ingresar'),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ),
      );
}
