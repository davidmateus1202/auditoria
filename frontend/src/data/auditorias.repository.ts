import { ApiError, descargar, eliminar, http, obtener } from '@/core/http/apiClient'

/** Una celda corregida en la vista previa. Se aplica al leer: el archivo
 * original se guarda intacto. */
export interface CorreccionCelda {
  hoja: string
  celda: string
  valor: string
}

export interface OpcionesCarga {
  sedeId?: number
  periodo?: string
  correcciones?: CorreccionCelda[]
}

/** Sube una o varias autoevaluaciones. El servidor procesa cada archivo por
 * separado, así que el que falle no tumba la carga de los demás: la
 * respuesta trae las que entraron y las que no, con su motivo. Un 422 con
 * `fallidas` no es un fallo de red: es la forma normal de la respuesta
 * parcial, y se devuelve igual que un éxito. */
export async function cargarAuditorias(
  archivos: File[],
  opciones: OpcionesCarga = {},
): Promise<Record<string, unknown>> {
  const formulario = new FormData()
  for (const archivo of archivos) formulario.append('archivos[]', archivo, archivo.name)
  if (opciones.sedeId != null) formulario.append('sede_id', String(opciones.sedeId))
  if (opciones.periodo) formulario.append('periodo', opciones.periodo)
  if (opciones.correcciones?.length) formulario.append('correcciones', JSON.stringify(opciones.correcciones))

  try {
    const r = await http.post<Record<string, unknown>>('/auditorias/cargar', formulario)
    return r.data
  } catch (error) {
    if (error instanceof ApiError && error.codigo === 422 && error.detalles?.fallidas !== undefined) {
      return error.detalles
    }
    throw error
  }
}

export async function auditorias(sedeId?: number): Promise<Record<string, unknown>[]> {
  const r = await obtener<{ datos?: Record<string, unknown>[] }>('/auditorias', {
    ...(sedeId != null ? { sede_id: sedeId } : {}),
  })
  return r.datos ?? []
}

export async function reconciliacionDeAuditoria(auditoriaId: number): Promise<Record<string, unknown>> {
  return obtener(`/auditorias/${auditoriaId}/reconciliacion`)
}

/** Publica la auditoría. Falla si queda alguna decisión sin tomar. */
export async function confirmarAuditoria(
  auditoriaId: number,
  decisiones: Record<number, Record<string, unknown>>,
): Promise<Record<string, unknown>> {
  const decisionesTexto: Record<string, unknown> = {}
  for (const [id, decision] of Object.entries(decisiones)) decisionesTexto[id] = decision

  const r = await http.post<Record<string, unknown>>(`/auditorias/${auditoriaId}/confirmar`, {
    decisiones: decisionesTexto,
  })
  return r.data
}

/** Con `forzar`, elimina aunque haya hallazgos que dependan de ella (el
 * servidor responde 409 con `requiere_confirmacion` si no se manda). */
export async function eliminarAuditoria(auditoriaId: number, forzar = false): Promise<void> {
  await eliminar(`/auditorias/${auditoriaId}${forzar ? '?forzar=1' : ''}`)
}

/** El archivo original tal como se subió, para previsualizarlo en el
 * navegador. Una auditoría de línea base no tiene archivo propio (404). */
export async function archivoDeAuditoria(auditoriaId: number): Promise<Blob> {
  return descargar(`/auditorias/${auditoriaId}/archivo`)
}
