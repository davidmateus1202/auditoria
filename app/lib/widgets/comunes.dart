import 'package:flutter/material.dart';

import '../dominio/enums.dart';
import '../dominio/modelos.dart';
import '../nucleo/tema.dart';

/// Etiqueta de estado. Encodifica el estado en forma y color, no solo en
/// número: en una lista larga hay que poder ver qué pide atención sin leer.
class Pastilla extends StatelessWidget {
  const Pastilla({super.key, required this.texto, required this.color, this.fondo});

  final String texto;
  final Color color;
  final Color? fondo;

  factory Pastilla.estado(BuildContext context, EstadoHallazgo estado) =>
      Pastilla(
        texto: estado.etiqueta,
        color: estado.color(context),
        fondo: estado.fondo(context),
      );

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
        decoration: BoxDecoration(
          color: fondo ?? color.withValues(alpha: 0.12),
          borderRadius: BorderRadius.circular(3),
        ),
        child: Text(
          texto.toUpperCase(),
          style: TextStyle(
            color: color,
            fontSize: 10.5,
            fontWeight: FontWeight.w700,
            letterSpacing: 0.5,
          ),
        ),
      );
}

/// Cifra grande con su rótulo. Se usa solo cuando el número ES el punto de la
/// pantalla; en el resto la información va en tablas.
class Cifra extends StatelessWidget {
  const Cifra({
    super.key,
    required this.valor,
    required this.rotulo,
    this.color,
    this.detalle,
  });

  final String valor;
  final String rotulo;
  final Color? color;
  final String? detalle;

  @override
  Widget build(BuildContext context) => Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(
            valor,
            style: TextStyle(
              fontSize: 30,
              height: 1,
              fontWeight: FontWeight.w700,
              letterSpacing: -1,
              color: color ?? Paleta.tinta,
              fontFeatures: const [FontFeature.tabularFigures()],
            ),
          ),
          const SizedBox(height: 6),
          Text(
            rotulo.toUpperCase(),
            style: const TextStyle(
              fontSize: 10.5,
              fontWeight: FontWeight.w600,
              letterSpacing: 0.8,
              color: Paleta.apagado,
              height: 1.3,
            ),
          ),
          if (detalle != null) ...[
            const SizedBox(height: 2),
            Text(
              detalle!,
              style: const TextStyle(fontSize: 11.5, color: Paleta.tinta2),
            ),
          ],
        ],
      );
}

/// Barra apilada del reparto de estados. Cuatro segmentos, sin leyenda propia:
/// la leyenda va aparte para que la barra se pueda repetir en una lista.
class BarraEstados extends StatelessWidget {
  const BarraEstados({super.key, required this.generales, this.altura = 10});

  final Generales generales;
  final double altura;

  @override
  Widget build(BuildContext context) {
    final total = generales.hallazgos;

    if (total == 0) {
      return Container(
        height: altura,
        decoration: BoxDecoration(
          color: Paleta.hundido,
          borderRadius: BorderRadius.circular(2),
        ),
      );
    }

    return ClipRRect(
      borderRadius: BorderRadius.circular(2),
      child: SizedBox(
        height: altura,
        child: Row(
          children: [
            for (final entrada in generales.porEstado.entries)
              if (entrada.value > 0)
                Expanded(
                  flex: entrada.value,
                  child: ColoredBox(color: entrada.key.color(context)),
                ),
          ],
        ),
      ),
    );
  }
}

class LeyendaEstados extends StatelessWidget {
  const LeyendaEstados({super.key, required this.generales});

  final Generales generales;

  @override
  Widget build(BuildContext context) => Wrap(
        spacing: 18,
        runSpacing: 6,
        children: [
          for (final entrada in generales.porEstado.entries)
            Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Container(
                  width: 10,
                  height: 10,
                  decoration: BoxDecoration(
                    color: entrada.key.color(context),
                    borderRadius: BorderRadius.circular(2),
                  ),
                ),
                const SizedBox(width: 6),
                Text(
                  '${entrada.key.etiqueta}  ',
                  style: const TextStyle(fontSize: 12.5, color: Paleta.tinta2),
                ),
                Text(
                  Formato.numero(entrada.value),
                  style: const TextStyle(
                    fontSize: 12.5,
                    fontWeight: FontWeight.w700,
                    fontFeatures: [FontFeature.tabularFigures()],
                  ),
                ),
              ],
            ),
        ],
      );
}

/// Barra horizontal con rótulo y valor: el gráfico que más se usa aquí, porque
/// los estándares tienen nombres largos y una barra vertical los recortaría.
class BarraHorizontal extends StatelessWidget {
  const BarraHorizontal({
    super.key,
    required this.rotulo,
    required this.valor,
    required this.maximo,
    this.porcentaje,
    this.color = Paleta.sello,
    this.onTap,
  });

  final String rotulo;
  final int valor;
  final int maximo;
  final double? porcentaje;
  final Color color;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final fraccion = maximo == 0 ? 0.0 : valor / maximo;

    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(4),
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 6, horizontal: 2),
        child: Row(
          children: [
            SizedBox(
              width: 128,
              child: Text(
                rotulo,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: const TextStyle(fontSize: 12.5, color: Paleta.tinta2, height: 1.25),
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: Container(
                height: 16,
                decoration: BoxDecoration(
                  color: Paleta.hundido,
                  borderRadius: BorderRadius.circular(2),
                  border: Border.all(color: Paleta.regla),
                ),
                child: FractionallySizedBox(
                  alignment: Alignment.centerLeft,
                  widthFactor: fraccion.clamp(0.0, 1.0),
                  child: DecoratedBox(
                    decoration: BoxDecoration(
                      color: color,
                      borderRadius: BorderRadius.circular(1),
                    ),
                  ),
                ),
              ),
            ),
            const SizedBox(width: 10),
            SizedBox(
              width: 42,
              child: Text(
                Formato.numero(valor),
                textAlign: TextAlign.right,
                style: const TextStyle(
                  fontSize: 13,
                  fontWeight: FontWeight.w700,
                  fontFeatures: [FontFeature.tabularFigures()],
                ),
              ),
            ),
            if (porcentaje != null)
              SizedBox(
                width: 54,
                child: Text(
                  Formato.porcentaje(porcentaje),
                  textAlign: TextAlign.right,
                  style: const TextStyle(
                    fontSize: 11.5,
                    color: Paleta.apagado,
                    fontFeatures: [FontFeature.tabularFigures()],
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }
}

/// Encabezado de sección con la regla que lo cierra.
class RotuloSeccion extends StatelessWidget {
  const RotuloSeccion(this.texto, {super.key, this.accion});

  final String texto;
  final Widget? accion;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(bottom: 10),
        child: Row(
          children: [
            Text(
              texto.toUpperCase(),
              style: const TextStyle(
                fontSize: 11,
                fontWeight: FontWeight.w700,
                letterSpacing: 1.2,
                color: Paleta.sello,
              ),
            ),
            const SizedBox(width: 10),
            const Expanded(child: Divider(height: 1)),
            if (accion != null) ...[const SizedBox(width: 10), accion!],
          ],
        ),
      );
}

/// Aviso con un motivo y, cuando toca, una salida.
class Aviso extends StatelessWidget {
  const Aviso({
    super.key,
    required this.mensaje,
    this.color = Paleta.sello,
    this.icono = Icons.info_outline,
    this.accion,
  });

  final String mensaje;
  final Color color;
  final IconData icono;
  final Widget? accion;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: color.withValues(alpha: 0.07),
          border: Border(left: BorderSide(color: color, width: 3)),
        ),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(icono, size: 19, color: color),
            const SizedBox(width: 11),
            Expanded(
              child: Text(
                mensaje,
                style: const TextStyle(fontSize: 13.5, height: 1.45, color: Paleta.tinta2),
              ),
            ),
            if (accion != null) ...[const SizedBox(width: 8), accion!],
          ],
        ),
      );
}

/// Estado vacío que explica qué falta y cómo llenarlo, en vez de una pantalla
/// en blanco que no dice nada.
class SinContenido extends StatelessWidget {
  const SinContenido({
    super.key,
    required this.titulo,
    required this.detalle,
    this.icono = Icons.inbox_outlined,
    this.accion,
  });

  final String titulo;
  final String detalle;
  final IconData icono;
  final Widget? accion;

  @override
  Widget build(BuildContext context) => Center(
        child: Padding(
          padding: const EdgeInsets.all(32),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(icono, size: 42, color: Paleta.regla),
              const SizedBox(height: 16),
              Text(
                titulo,
                textAlign: TextAlign.center,
                style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w600),
              ),
              const SizedBox(height: 8),
              Text(
                detalle,
                textAlign: TextAlign.center,
                style: const TextStyle(fontSize: 13.5, color: Paleta.apagado, height: 1.5),
              ),
              if (accion != null) ...[const SizedBox(height: 20), accion!],
            ],
          ),
        ),
      );
}

/// Pantalla de error con el mensaje real del servidor y un botón de reintentar.
class PantallaError extends StatelessWidget {
  const PantallaError({super.key, required this.error, required this.reintentar});

  final Object error;
  final VoidCallback reintentar;

  @override
  Widget build(BuildContext context) => SinContenido(
        icono: Icons.error_outline,
        titulo: 'No se pudo cargar',
        detalle: '$error',
        accion: FilledButton.icon(
          onPressed: reintentar,
          icon: const Icon(Icons.refresh, size: 18),
          label: const Text('Reintentar'),
        ),
      );
}

/// Tarjeta con borde, la unidad de composición de casi todas las pantallas.
class Bloque extends StatelessWidget {
  const Bloque({super.key, required this.child, this.padding});

  final Widget child;
  final EdgeInsets? padding;

  @override
  Widget build(BuildContext context) => Container(
        width: double.infinity,
        padding: padding ?? const EdgeInsets.all(16),
        decoration: BoxDecoration(
          color: Paleta.superficie,
          borderRadius: BorderRadius.circular(4),
          border: Border.all(color: Paleta.regla),
        ),
        child: child,
      );
}
