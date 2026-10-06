import { actualizar, enviar, obtener as obtenerPeticion } from '@/core/http/apiClient'
import type { EstadoHallazgoValor } from '@/domain/enums'
import { hallazgoDesdeJson, type Hallazgo } from '@/domain/models'

export interface FiltroHallazgos {
  sedeId?: number | null
  estandar?: string | null
  estado?: EstadoHallazgoValor | null
  buscar?: string
  soloVigentes?: boolean
  pagina?: number
}

export interface ResultadoHallazgos {
  datos: Hallazgo[]
  total: number
}

export async function listar(filtro: FiltroHallazgos): Promise<ResultadoHallazgos> {
  const r = await obtenerPeticion<{ data?: Record<string, unknown>[]; total?: number }>('/hallazgos', {
    ...(filtro.sedeId != null ? { sede_id: filtro.sedeId } : {}),
    ...(filtro.estandar ? { estandar: filtro.estandar } : {}),
    ...(filtro.estado ? { estado: filtro.estado } : {}),
    // En una cadena de consulta el booleano viaja como 1/0: la regla
    // `boolean` de Laravel no acepta las palabras "true"/"false".
    ...(filtro.buscar ? { buscar: filtro.buscar } : {}),
    ...(filtro.soloVigentes ? { solo_vigentes: 1 } : {}),
    orden: 'sede',
    page: filtro.pagina ?? 1,
    por_pagina: 50,
  })

  return {
    datos: (r.data ?? []).map(hallazgoDesdeJson),
    total: r.total ?? 0,
  }
}

export async function obtener(hallazgoId: number): Promise<Hallazgo> {
  const r = await obtenerPeticion<{ datos: Record<string, unknown> }>(`/hallazgos/${hallazgoId}`)
  return hallazgoDesdeJson(r.datos)
}

export async function lineaTiempo(hallazgoId: number): Promise<Record<string, unknown>> {
  return obtenerPeticion(`/hallazgos/${hallazgoId}/linea-tiempo`)
}

export interface CambioEstadoHallazgo {
  estado: EstadoHallazgoValor
  evidencia?: string
  accionPropuesta?: string
  responsable?: string
}

/** Cerrar exige evidencia: el backend lo rechaza sin ella, y con razón — un
 * cierre sin constancia es lo que hace indefendible un consolidado. */
export async function cambiarEstado(hallazgoId: number, cambio: CambioEstadoHallazgo): Promise<void> {
  await actualizar(`/hallazgos/${hallazgoId}/estado`, {
    estado: cambio.estado,
    ...(cambio.evidencia !== undefined ? { evidencia: cambio.evidencia } : {}),
    ...(cambio.accionPropuesta !== undefined ? { accion_propuesta: cambio.accionPropuesta } : {}),
    ...(cambio.responsable !== undefined ? { responsable: cambio.responsable } : {}),
  })
}

export interface NuevoHallazgo {
  sedeId: number
  estandarCodigo: string
  descripcion: string
  estado?: EstadoHallazgoValor
  accionPropuesta?: string
  responsable?: string
  evidencia?: string
}

/** Registra un hallazgo a mano, sin pasar por un Excel. Si hay un corte
 * abierto, entra directo como reportado de ese mes. */
export async function crear(nuevo: NuevoHallazgo): Promise<Hallazgo> {
  const r = await enviar<{ datos: Record<string, unknown> }>('/hallazgos', {
    sede_id: nuevo.sedeId,
    estandar_codigo: nuevo.estandarCodigo,
    descripcion: nuevo.descripcion,
    ...(nuevo.estado ? { estado: nuevo.estado } : {}),
    ...(nuevo.accionPropuesta ? { accion_propuesta: nuevo.accionPropuesta } : {}),
    ...(nuevo.responsable ? { responsable: nuevo.responsable } : {}),
    ...(nuevo.evidencia ? { evidencia: nuevo.evidencia } : {}),
  })
  return hallazgoDesdeJson(r.datos)
}
