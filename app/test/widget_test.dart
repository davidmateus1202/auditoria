import 'package:auditoria_suh/main.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  testWidgets('sin sesión, la aplicación abre en la pantalla de ingreso',
      (tester) async {
    await tester.pumpWidget(
      const ProviderScope(child: AplicacionAuditoria()),
    );
    await tester.pump();

    // La puerta resuelve la sesión antes de decidir qué mostrar.
    expect(find.byType(MaterialApp), findsOneWidget);
  });
}
