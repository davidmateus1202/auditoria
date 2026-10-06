/** Dispara la descarga de un blob en el navegador. Equivalente web de
 * app/lib/nucleo/descargas.dart: en el navegador no hay diálogo de "guardar
 * como" que podamos esperar, el navegador decide dónde poner el archivo. */
export function guardarArchivo(nombre: string, blob: Blob): void {
  const url = URL.createObjectURL(blob)
  const enlace = document.createElement('a')
  enlace.href = url
  enlace.download = nombre
  document.body.appendChild(enlace)
  enlace.click()
  document.body.removeChild(enlace)
  URL.revokeObjectURL(url)
}
