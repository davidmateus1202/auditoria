import 'package:flutter/material.dart';

/// Estado de gestión de un hallazgo.
///
/// El color no es decorativo: en una pantalla que se opera de un vistazo, el
/// estado tiene que leerse antes que el número.
enum EstadoHallazgo {
  abierto('abierto', 'Abierto'),
  abiertoConEvidencia('abierto_evidencia', 'Abierto con evidencia'),
  cerrado('cerrado', 'Cerrado'),
  sinDato('sin_dato', 'Sin dato');

  const EstadoHallazgo(this.valor, this.etiqueta);

  final String valor;
  final String etiqueta;

  static EstadoHallazgo desde(String? valor) => switch (valor) {
        'abierto' => EstadoHallazgo.abierto,
        'abierto_evidencia' => EstadoHallazgo.abiertoConEvidencia,
        'cerrado' => EstadoHallazgo.cerrado,
        _ => EstadoHallazgo.sinDato,
      };

  bool get esVigente => this != EstadoHallazgo.cerrado;

  Color color(BuildContext context) => switch (this) {
        EstadoHallazgo.abierto => const Color(0xFFA32E22),
        EstadoHallazgo.abiertoConEvidencia => const Color(0xFF8A6410),
        EstadoHallazgo.cerrado => const Color(0xFF2A6A4E),
        EstadoHallazgo.sinDato => const Color(0xFF7E867F),
      };

  Color fondo(BuildContext context) => switch (this) {
        EstadoHallazgo.abierto => const Color(0xFFF2DFDB),
        EstadoHallazgo.abiertoConEvidencia => const Color(0xFFF0E6CE),
        EstadoHallazgo.cerrado => const Color(0xFFDBE9E0),
        EstadoHallazgo.sinDato => const Color(0xFFE2E4DF),
      };
}

/// Destino que el reconciliador propone para un hallazgo.
enum DestinoReconciliacion {
  persiste('persiste', 'Persiste'),
  nuevo('nuevo', 'Nuevo'),
  reincidencia('reincidencia', 'Reincidencia'),
  candidatoCierre('candidato_cierre', 'Candidato a cierre'),
  noVerificado('no_verificado', 'No verificado'),
  ausenteDelCorte('ausente_corte', 'Ausente del corte'),
  revision('revision', 'Requiere revisión'),
  conflicto('conflicto', 'Conflicto');

  const DestinoReconciliacion(this.valor, this.etiqueta);

  final String valor;
  final String etiqueta;

  static DestinoReconciliacion desde(String? valor) =>
      DestinoReconciliacion.values.firstWhere(
        (d) => d.valor == valor,
        orElse: () => DestinoReconciliacion.revision,
      );

  /// Los que no se aplican solos: alguien tiene que decidir.
  bool get exigeConfirmacion => const {
        DestinoReconciliacion.candidatoCierre,
        DestinoReconciliacion.revision,
        DestinoReconciliacion.conflicto,
        DestinoReconciliacion.reincidencia,
      }.contains(this);

  Color color() => switch (this) {
        DestinoReconciliacion.persiste => const Color(0xFF2E3D8F),
        DestinoReconciliacion.nuevo => const Color(0xFF2E3D8F),
        DestinoReconciliacion.reincidencia => const Color(0xFF8A6410),
        DestinoReconciliacion.candidatoCierre => const Color(0xFFA32E22),
        DestinoReconciliacion.conflicto => const Color(0xFFA32E22),
        DestinoReconciliacion.revision => const Color(0xFF8A6410),
        _ => const Color(0xFF7E867F),
      };
}

/// Agrupaciones del consolidado. Son los tres cortes que pidió el usuario y
/// salen del mismo endpoint: la pantalla solo cambia cómo los dibuja.
enum AgrupacionConsolidado {
  sede('sede', 'Por sede'),
  estandar('estandar', 'Por estándar'),
  sedeEstandar('sede_estandar', 'Sede × estándar');

  const AgrupacionConsolidado(this.valor, this.etiqueta);

  final String valor;
  final String etiqueta;
}
