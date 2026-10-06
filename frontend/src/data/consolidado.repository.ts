import { descargar, enviar, obtener } from '@/core/http/apiClient'
import { consolidadoDesdeJson, puntoSerieDesdeJson, type Consolidado, type PuntoSerie } from '@/domain/models'

export interface FiltroConsolidado {
  periodo?: string
  sedes?: number[]
}

export async function consolidado(filtro: FiltroConsolidado = {}): Promise<Consolidado> {
  const r = await enviar<Record<string, unknown>>('/consolidado', {
    ...(filtro.periodo ? { periodo: filtro.periodo } : {}),
    ...(filtro.sedes && filtro.sedes.length > 0 ? { sedes: filtro.sedes } : {}),
  })
  return consolidadoDesdeJson(r)
}

export async function serie(sedes: number[] = []): Promise<PuntoSerie[]> {
  const r = await obtener<{ datos?: Record<string, unknown>[] }>('/consolidado/serie', {
    ...(sedes.length > 0 ? { sedes } : {}),
  })
  return (r.datos ?? []).map(puntoSerieDesdeJson)
}

export async function exportarConsolidado(filtro: FiltroConsolidado = {}): Promise<Blob> {
  return descargar('/consolidado/exportar', {
    ...(filtro.periodo ? { periodo: filtro.periodo } : {}),
    ...(filtro.sedes && filtro.sedes.length > 0 ? { sedes: filtro.sedes } : {}),
  })
}
