import type { QueryClient } from '@tanstack/vue-query'

/**
 * Todo lo que muestra cifras o estados de hallazgos. Un mismo cambio (cerrar
 * un hallazgo, publicar un cruce, subir la matriz del mes) mueve a la vez el
 * tablero, el consolidado, la serie, los conteos por sede, el corte y las
 * listas; invalidar por pedazos dejaba pantallas con datos viejos.
 *
 * Solo se vuelven a pedir las consultas que están en pantalla; las demás
 * quedan marcadas y se refrescan al abrirlas. El catálogo de estándares no
 * cambia con estas operaciones y queda fuera.
 */
const CLAVES_DE_DATOS = [
  'consolidado',
  'serie',
  'sedes',
  'cortes',
  'corte',
  'hallazgosCorte',
  'hallazgos',
  'hallazgo',
  'lineaTiempo',
  'auditorias',
  'cruce',
  // Cargar o eliminar una auditoría agrega o quita sus fotos.
  'evidencias',
] as const

export function invalidarDatos(queryClient: QueryClient): Promise<void> {
  return Promise.all(CLAVES_DE_DATOS.map((clave) => queryClient.invalidateQueries({ queryKey: [clave] }))).then(
    () => undefined,
  )
}
