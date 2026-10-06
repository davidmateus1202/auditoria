import { enviar, obtener } from '@/core/http/apiClient'
import { estandarDesdeJson, sedeDesdeJson, type Estandar, type Sede } from '@/domain/models'

export async function sedes(buscar?: string): Promise<Sede[]> {
  const r = await obtener<{ datos?: Record<string, unknown>[] }>('/sedes', {
    ...(buscar ? { buscar } : {}),
  })

  return (r.datos ?? []).map(sedeDesdeJson)
}

export async function crearSede(codigo: string, nombre: string): Promise<Sede> {
  const r = await enviar<{ datos: Record<string, unknown> }>('/sedes', { codigo, nombre })
  return sedeDesdeJson(r.datos)
}

export async function estandares(): Promise<Estandar[]> {
  const r = await obtener<{ datos?: Record<string, unknown>[] }>('/catalogos/estandares')
  return (r.datos ?? []).map(estandarDesdeJson)
}
