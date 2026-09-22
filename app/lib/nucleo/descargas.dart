import 'dart:typed_data';

import 'package:file_picker/file_picker.dart';
import 'package:flutter/foundation.dart' show kIsWeb;

/// Guarda bytes en un archivo elegido por la persona, o dispara la descarga
/// del navegador en web.
///
/// En web, file_picker siempre devuelve `null` así el archivo se haya
/// descargado —el navegador no expone una ruta real—, así que ahí se asume
/// éxito si no lanzó una excepción. En el resto de plataformas, `null` sí
/// significa que la persona canceló el diálogo de guardado.
Future<bool> guardarArchivo({
  required String nombre,
  required List<int> bytes,
  required List<String> extensiones,
}) async {
  final ruta = await FilePicker.saveFile(
    fileName: nombre,
    bytes: Uint8List.fromList(bytes),
    type: FileType.custom,
    allowedExtensions: extensiones,
  );

  return kIsWeb || ruta != null;
}
