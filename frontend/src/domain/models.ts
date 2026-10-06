import { EstadoHallazgo, type EstadoHallazgoInfo } from './enums'

/**
 * Utilidades de lectura tolerante: la API puede omitir campos opcionales y
 * una pantalla no debería caerse por eso. Calcado de app/lib/dominio/modelos.dart.
 */
function entero(v: unknown): number {
  if (typeof v === 'number') return v
  const n = Number.parseInt(String(v ?? ''), 10)
  return Number.isNaN(n) ? 0 : n
}

function decimal(v: unknown): number | null {
  if (v === null || v === undefined) return null
  if (typeof v === 'number') return v
  const n = Number.parseFloat(String(v))
  return Number.isNaN(n) ? null : n
}

function texto(v: unknown): string {
  return v === null || v === undefined ? '' : String(v)
}

function mapa(v: unknown): Record<string, unknown> {
  return v !== null && typeof v === 'object' ? (v as Record<string, unknown>) : {}
}

export interface Sede {
  id: number
  codigo: string
  nombre: string
  municipio: string
  responsable: string | null
  activa: boolean
  hallazgosVigentes: number
  hallazgosCerrados: number
  auditorias: number
}

export function sedeDesdeJson(j: Record<string, unknown>): Sede {
  const hallazgosVigentes = entero(j.hallazgos_vigentes)
  const hallazgosCerrados = entero(j.hallazgos_cerrados)
  return {
    id: entero(j.id),
    codigo: texto(j.codigo),
    nombre: texto(j.nombre),
    municipio: j.municipio ? texto(j.municipio) : 'Villavicencio',
    responsable: (j.responsable as string | null) ?? null,
    activa: j.activa === true || j.activa === 1,
    hallazgosVigentes,
    hallazgosCerrados,
    auditorias: entero(j.auditorias_count),
  }
}

export function sedeTotalHallazgos(s: Sede): number {
  return s.hallazgosVigentes + s.hallazgosCerrados
}

export function sedeAvance(s: Sede): number | null {
  const total = sedeTotalHallazgos(s)
  return total === 0 ? null : s.hallazgosCerrados / total
}

export interface Estandar {
  codigo: string
  nombre: string
  orden: number
  esNormativo: boolean
}

export function estandarDesdeJson(j: Record<string, unknown>): Estandar {
  return {
    codigo: texto(j.codigo),
    nombre: texto(j.nombre),
    orden: entero(j.orden),
    esNormativo: j.es_normativo === true || j.es_normativo === 1,
  }
}

export interface Hallazgo {
  id: number
  descripcion: string
  estado: EstadoHallazgoInfo
  codigoEstandar: string
  nombreEstandar: string
  sedeCodigo: string
  sedeNombre: string
  accionPropuesta: string | null
  responsable: string | null
  evidencia: string | null
  vecesReportado: number
  servicio: string | null
}

export function hallazgoDesdeJson(j: Record<string, unknown>): Hallazgo {
  const estandar = mapa(j.estandar)
  const sede = mapa(j.sede)
  const servicio = mapa(j.servicio)

  return {
    id: entero(j.id),
    descripcion: texto(j.descripcion),
    estado: EstadoHallazgo.desde(j.estado as string | null),
    codigoEstandar: texto(j.estandar_codigo),
    nombreEstandar: texto(estandar.nombre),
    sedeCodigo: texto(sede.codigo),
    sedeNombre: texto(sede.nombre),
    accionPropuesta: (j.accion_propuesta as string | null) ?? null,
    responsable: (j.responsable as string | null) ?? null,
    evidencia: (j.evidencia as string | null) ?? null,
    vecesReportado: entero(j.veces_reportado) || 1,
    servicio: (servicio.nombre as string | null) ?? null,
  }
}

export interface Corte {
  periodo: string
  estado: string
  hallazgos: number
  reportados: number
  pendientes: number
  porSede: Record<string, Record<string, number>>
}

export function corteDesdeJson(j: Record<string, unknown>): Corte {
  const porSedeJson = mapa(j.por_sede)
  const porSede: Record<string, Record<string, number>> = {}
  for (const [clave, valor] of Object.entries(porSedeJson)) {
    const fila = mapa(valor)
    const filaNumerica: Record<string, number> = {}
    for (const [k, v] of Object.entries(fila)) filaNumerica[k] = entero(v)
    porSede[clave] = filaNumerica
  }

  return {
    periodo: texto(j.periodo),
    estado: texto(j.estado),
    hallazgos: entero(j.hallazgos),
    reportados: entero(j.reportados),
    pendientes: entero(j.pendientes),
    porSede,
  }
}

export function corteAbierto(c: Corte): boolean {
  return c.estado === 'abierto'
}

export function corteAvanceDelMes(c: Corte): number {
  return c.hallazgos === 0 ? 0 : c.reportados / c.hallazgos
}

/** Bloque de cifras que se repite en todas las vistas del consolidado. */
export interface Generales {
  hallazgos: number
  cerrados: number
  abiertosConEvidencia: number
  abiertos: number
  sinDato: number
  sinReportar: number
  pctAvance: number | null
  pctConGestion: number | null
}

export function generalesDesdeJson(j: Record<string, unknown>): Generales {
  return {
    hallazgos: entero(j.hallazgos),
    cerrados: entero(j.cerrados),
    abiertosConEvidencia: entero(j.abiertos_con_evidencia),
    abiertos: entero(j.abiertos),
    sinDato: entero(j.sin_dato),
    sinReportar: entero(j.sin_reportar),
    pctAvance: decimal(j.pct_avance),
    pctConGestion: decimal(j.pct_con_gestion),
  }
}

export function generalesPorEstado(g: Generales): Array<[EstadoHallazgoInfo, number]> {
  return [
    [EstadoHallazgo.abierto, g.abiertos],
    [EstadoHallazgo.abiertoConEvidencia, g.abiertosConEvidencia],
    [EstadoHallazgo.cerrado, g.cerrados],
    [EstadoHallazgo.sinDato, g.sinDato],
  ]
}

/** Una fila de cualquiera de los tres cortes del consolidado. */
export interface FilaConsolidado {
  clave: string
  nombre: string
  generales: Generales
  pctDelTotal: number | null
  estandares: Record<string, number>
  estandarDominante: string | null
  pctDominante: number | null
  mesAntiguoMeses: number
}

export function filaConsolidadoDesdeJson(j: Record<string, unknown>): FilaConsolidado {
  const estandaresJson = mapa(j.estandares)
  const estandares: Record<string, number> = {}
  for (const [k, v] of Object.entries(estandaresJson)) estandares[k] = entero(v)

  return {
    clave: texto(j.sede ?? j.estandar),
    nombre: texto(j.nombre),
    generales: generalesDesdeJson(j),
    pctDelTotal: decimal(j.pct_del_total),
    estandares,
    estandarDominante: (j.estandar_dominante as string | null) ?? null,
    pctDominante: decimal(j.pct_dominante),
    mesAntiguoMeses: entero(j.mas_antiguo_meses),
  }
}

export function filaConsolidadoTotal(f: FilaConsolidado): number {
  const valores = Object.values(f.estandares)
  if (valores.length === 0) return f.generales.hallazgos
  return valores.reduce((a, b) => a + b, 0)
}

export type AgrupacionClave = 'sede' | 'estandar' | 'sede_estandar'

export interface Consolidado {
  periodo: string
  corteCerrado: boolean
  generales: Generales
  porSede: FilaConsolidado[]
  porEstandar: FilaConsolidado[]
  sedePorEstandar: FilaConsolidado[]
}

function filasDesdeJson(lista: unknown): FilaConsolidado[] {
  return Array.isArray(lista) ? lista.map((e) => filaConsolidadoDesdeJson(e as Record<string, unknown>)) : []
}

export function consolidadoDesdeJson(j: Record<string, unknown>): Consolidado {
  return {
    periodo: texto(j.periodo),
    corteCerrado: j.corte_cerrado === true,
    generales: generalesDesdeJson(mapa(j.generales)),
    porSede: filasDesdeJson(j.por_sede),
    porEstandar: filasDesdeJson(j.por_estandar),
    sedePorEstandar: filasDesdeJson(j.sede_por_estandar),
  }
}

export function consolidadoAgrupadoPor(c: Consolidado, agrupacion: AgrupacionClave): FilaConsolidado[] {
  switch (agrupacion) {
    case 'sede':
      return c.porSede
    case 'estandar':
      return c.porEstandar
    case 'sede_estandar':
      return c.sedePorEstandar
  }
}

export interface PuntoSerie {
  periodo: string
  generales: Generales
  cerrado: boolean
}

export function puntoSerieDesdeJson(j: Record<string, unknown>): PuntoSerie {
  return {
    periodo: texto(j.periodo),
    generales: generalesDesdeJson(j),
    cerrado: j.cerrado === true,
  }
}

export interface Usuario {
  id: number
  nombre: string
  email: string
}

export function usuarioDesdeJson(j: Record<string, unknown>): Usuario {
  return {
    id: entero(j.id),
    nombre: texto(j.nombre),
    email: texto(j.email),
  }
}
