import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

/// Paleta del expediente técnico: papel gris verdoso, tinta de sello para lo
/// interactivo, y el semáforo reservado para el estado del hallazgo.
///
/// El acento nunca se usa para señalar gravedad: si el azul y el rojo
/// compitieran, el estado dejaría de leerse de un vistazo, que es justo lo que
/// esta aplicación tiene que resolver.
abstract final class Paleta {
  static const papel = Color(0xFFEDEEEA);
  static const superficie = Color(0xFFF7F8F5);
  static const superficie2 = Color(0xFFE3E5DF);
  static const hundido = Color(0xFFE7E9E3);
  static const tinta = Color(0xFF181B18);
  static const tinta2 = Color(0xFF3D423E);
  static const apagado = Color(0xFF767F78);
  static const regla = Color(0xFFCDD1CA);
  static const sello = Color(0xFF2E3D8F);
  static const selloSuave = Color(0xFFDFE3F3);
  static const critico = Color(0xFFA32E22);
  static const alerta = Color(0xFF8A6410);
  static const bien = Color(0xFF2A6A4E);
}

ThemeData construirTema() {
  final base = ThemeData(
    useMaterial3: true,
    colorScheme: ColorScheme.fromSeed(
      seedColor: Paleta.sello,
      surface: Paleta.superficie,
      brightness: Brightness.light,
    ),
    scaffoldBackgroundColor: Paleta.papel,
  );

  return base.copyWith(
    textTheme: base.textTheme.apply(
      bodyColor: Paleta.tinta,
      displayColor: Paleta.tinta,
    ),
    appBarTheme: const AppBarTheme(
      backgroundColor: Paleta.superficie,
      foregroundColor: Paleta.tinta,
      elevation: 0,
      scrolledUnderElevation: 1,
      surfaceTintColor: Colors.transparent,
      titleTextStyle: TextStyle(
        color: Paleta.tinta,
        fontSize: 18,
        fontWeight: FontWeight.w600,
        letterSpacing: -0.2,
      ),
    ),
    cardTheme: CardThemeData(
      color: Paleta.superficie,
      elevation: 0,
      margin: EdgeInsets.zero,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(4),
        side: const BorderSide(color: Paleta.regla),
      ),
    ),
    dividerTheme: const DividerThemeData(color: Paleta.regla, thickness: 1, space: 1),
    inputDecorationTheme: InputDecorationTheme(
      filled: true,
      fillColor: Paleta.superficie,
      contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
      border: OutlineInputBorder(
        borderRadius: BorderRadius.circular(4),
        borderSide: const BorderSide(color: Paleta.regla),
      ),
      enabledBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(4),
        borderSide: const BorderSide(color: Paleta.regla),
      ),
      focusedBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(4),
        borderSide: const BorderSide(color: Paleta.sello, width: 2),
      ),
    ),
    filledButtonTheme: FilledButtonThemeData(
      style: FilledButton.styleFrom(
        backgroundColor: Paleta.sello,
        foregroundColor: Colors.white,
        padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 16),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(4)),
        textStyle: const TextStyle(fontWeight: FontWeight.w600, fontSize: 15),
      ),
    ),
    chipTheme: ChipThemeData(
      backgroundColor: Paleta.superficie,
      side: const BorderSide(color: Paleta.regla),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(4)),
      labelStyle: const TextStyle(fontSize: 13, color: Paleta.tinta2),
    ),
    tabBarTheme: const TabBarThemeData(
      labelColor: Paleta.sello,
      unselectedLabelColor: Paleta.apagado,
      indicatorColor: Paleta.sello,
      dividerColor: Paleta.regla,
    ),
    snackBarTheme: SnackBarThemeData(
      backgroundColor: Paleta.tinta,
      contentTextStyle: const TextStyle(color: Colors.white),
      behavior: SnackBarBehavior.floating,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(4)),
    ),
  );
}

/// Formatos en español de Colombia: coma decimal y punto de miles.
abstract final class Formato {
  static final _entero = NumberFormat.decimalPattern('es_CO');
  static final _porcentaje = NumberFormat('#,##0.0', 'es_CO');

  static String numero(num valor) => _entero.format(valor);

  static String porcentaje(double? valor, {int decimales = 1}) {
    if (valor == null) return '—';
    return '${_porcentaje.format(valor * 100)} %';
  }

  static String periodo(String aaaaMm) {
    final partes = aaaaMm.split('-');
    if (partes.length != 2) return aaaaMm;

    const meses = [
      'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
      'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre',
    ];

    final mes = int.tryParse(partes[1]);
    if (mes == null || mes < 1 || mes > 12) return aaaaMm;

    return '${meses[mes - 1]} de ${partes[0]}';
  }
}
