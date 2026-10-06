import { descargar, enviar, http, obtener } from '@/core/http/apiClient'
import type { EstadoHallazgoValor } from '@/domain/enums'
import { corteDesdeJson, type Corte } from '@/domain/models'

export async function cortes(): Promise<Corte[]> {
  const r = await obtener<{ datos?: Record<string, unknown>[] }>('/cortes')
  return (r.datos ?? []).map(corteDesdeJson)
}

export async function corte(periodo: string): Promise<Corte> {
  return corteDesdeJson(await obtener(`/cortes/${periodo}`))
}

export async function abrirCorte(periodo: string): Promise<Corte> {
  const r = await enviar<{ datos: Record<string, unknown> }>('/cortes', { periodo })
  return corteDesdeJson(r.datos)
}

export async function cerrarCorte(periodo: string): Promise<void> {
  await enviar(`/cortes/${periodo}/cerrar`)
}

export async function reabrirCorte(periodo: string): Promise<void> {
  await enviar(`/cortes/${periodo}/reabrir`)
}

export async function reconciliacionDelCorte(periodo: string): Promise<Record<string, unknown>> {
  return obtener(`/cortes/${periodo}/reconciliacion`)
}

export interface ActualizacionHallazgoCorte {
  estado: EstadoHallazgoValor
  accionPropuesta?: string
  responsable?: string
  evidencia?: string
}

export async function actualizarEnCorte(
  periodo: string,
  hallazgoId: number,
  cambio: ActualizacionHallazgoCorte,
): Promise<void> {
  await http.put(`/cortes/${periodo}/hallazgos/${hallazgoId}`, {
    estado: cambio.estado,
    ...(cambio.accionPropuesta !== undefined ? { accion_propuesta: cambio.accionPropuesta } : {}),
    ...(cambio.responsable !== undefined ? { responsable: cambio.responsable } : {}),
    ...(cambio.evidencia !== undefined ? { evidencia: cambio.evidencia } : {}),
  })
}

export interface HallazgoDeCorte {
  hallazgoId: number
  descripcion: string
  sede: { id: number; codigo: string; nombre: string }
  estandar: { codigo: string; nombre: string }
  estado: EstadoHallazgoValor
  presenteEnCorte: boolean
  mesesAbierto: number
  accionPropuesta: string | null
  responsable: string | null
  evidencia: string | null
}

export interface FiltroHallazgosCorte {
  sedeId?: number | null
  estandar?: string | null
  estado?: EstadoHallazgoValor | null
  buscar?: string
  pagina?: number
}

export interface ResultadoHallazgosCorte {
  datos: HallazgoDeCorte[]
  total: number
}

function hallazgoDeCorteDesdeJson(j: Record<string, unknown>): HallazgoDeCorte {
  const sede = (j.sede as Record<string, unknown>) ?? {}
  const estandar = (j.estandar as Record<string, unknown>) ?? {}

  return {
    hallazgoId: Number(j.hallazgo_id),
    descripcion: String(j.descripcion ?? ''),
    sede: { id: Number(sede.id), codigo: String(sede.codigo ?? ''), nombre: String(sede.nombre ?? '') },
    estandar: { codigo: String(estandar.codigo ?? ''), nombre: String(estandar.nombre ?? '') },
    estado: j.estado as EstadoHallazgoValor,
    presenteEnCorte: j.presente_en_corte === true,
    mesesAbierto: Number(j.meses_abierto ?? 0),
    accionPropuesta: (j.accion_propuesta as string | null) ?? null,
    responsable: (j.responsable as string | null) ?? null,
    evidencia: (j.evidencia as string | null) ?? null,
  }
}

/** El detalle hallazgo por hallazgo del corte, para editarlo a mano. Es la
 * misma foto que alimenta el consolidado: un cambio aquí sí se refleja en él
 * — a diferencia de `cambiarEstado` (hallazgos.repository.ts), que solo
 * toca el estado «en vivo» del hallazgo. */
export async function hallazgosDelCorte(
  periodo: string,
  filtro: FiltroHallazgosCorte = {},
): Promise<ResultadoHallazgosCorte> {
  const r = await obtener<{ data?: Record<string, unknown>[]; total?: number }>(`/cortes/${periodo}/hallazgos`, {
    ...(filtro.sedeId != null ? { sede_id: filtro.sedeId } : {}),
    ...(filtro.estandar ? { estandar: filtro.estandar } : {}),
    ...(filtro.estado ? { estado: filtro.estado } : {}),
    ...(filtro.buscar ? { buscar: filtro.buscar } : {}),
    page: filtro.pagina ?? 1,
    por_pagina: 50,
  })

  return {
    datos: (r.data ?? []).map(hallazgoDeCorteDesdeJson),
    total: r.total ?? 0,
  }
}

export async function descargarMatriz(periodo: string): Promise<Blob> {
  return descargar(`/cortes/${periodo}/matriz`)
}

export async function subirMatriz(periodo: string, archivo: File): Promise<Record<string, unknown>> {
  const formulario = new FormData()
  formulario.append('archivo', archivo, archivo.name)

  const r = await http.post<Record<string, unknown>>(`/cortes/${periodo}/seguimiento`, formulario)
  return r.data
}
