import { baseUrl, obtener } from '@/core/http/apiClient'

export interface FotoEvidencia {
  id: number
  hoja: string
  celda: string | null
  orden: number
  ancho: number | null
  alto: number | null
  bytes: number
  miniaturaUrl: string
  imagenUrl: string
}

export interface AuditoriaConFotos {
  id: number
  version: number
  periodo: string | null
  estado: string
  origen: string
  sede: { id: number; codigo: string; nombre: string } | null
}

export interface GrupoEvidencias {
  auditoria: AuditoriaConFotos
  fotos: FotoEvidencia[]
}

/**
 * El servidor entrega las URL de imagen relativas a la raíz («/api/…») y ya
 * firmadas. Se les antepone el origen de la API: en desarrollo es el mismo
 * del frontend (proxy de Vite); en producción puede ser otro dominio.
 */
function urlDeApi(ruta: string): string {
  return baseUrl.replace(/\/api\/?$/, '') + ruta
}

export async function evidencias(sedeId?: number): Promise<{ total: number; grupos: GrupoEvidencias[] }> {
  const r = await obtener<{ total?: number; datos?: Record<string, unknown>[] }>('/evidencias', {
    ...(sedeId != null ? { sede_id: sedeId } : {}),
  })

  return {
    total: r.total ?? 0,
    grupos: (r.datos ?? []).map((g) => ({
      auditoria: g.auditoria as AuditoriaConFotos,
      fotos: ((g.fotos as Record<string, unknown>[] | undefined) ?? []).map((f) => ({
        id: f.id as number,
        hoja: f.hoja as string,
        celda: (f.celda as string | null) ?? null,
        orden: f.orden as number,
        ancho: (f.ancho as number | null) ?? null,
        alto: (f.alto as number | null) ?? null,
        bytes: (f.bytes as number) ?? 0,
        miniaturaUrl: urlDeApi(f.miniatura_url as string),
        imagenUrl: urlDeApi(f.imagen_url as string),
      })),
    })),
  }
}
