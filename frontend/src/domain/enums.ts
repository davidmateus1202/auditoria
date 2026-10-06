/**
 * Estado de gestión de un hallazgo.
 *
 * El color no es decorativo: en una pantalla que se opera de un vistazo, el
 * estado tiene que leerse antes que el número. Calcado de
 * app/lib/dominio/enums.dart.
 */
export type EstadoHallazgoValor = 'abierto' | 'abierto_evidencia' | 'cerrado' | 'sin_dato'

export interface EstadoHallazgoInfo {
  valor: EstadoHallazgoValor
  etiqueta: string
  color: string
  fondo: string
  esVigente: boolean
}

const ESTADOS_HALLAZGO: Record<EstadoHallazgoValor, EstadoHallazgoInfo> = {
  abierto: {
    valor: 'abierto',
    etiqueta: 'Abierto',
    color: '#A32E22',
    fondo: '#F2DFDB',
    esVigente: true,
  },
  abierto_evidencia: {
    valor: 'abierto_evidencia',
    etiqueta: 'Abierto con evidencia',
    color: '#8A6410',
    fondo: '#F0E6CE',
    esVigente: true,
  },
  cerrado: {
    valor: 'cerrado',
    etiqueta: 'Cerrado',
    color: '#2A6A4E',
    fondo: '#DBE9E0',
    esVigente: false,
  },
  sin_dato: {
    valor: 'sin_dato',
    etiqueta: 'Sin dato',
    color: '#7E867F',
    fondo: '#E2E4DF',
    esVigente: true,
  },
}

export const EstadoHallazgo = {
  valores: Object.values(ESTADOS_HALLAZGO),
  abierto: ESTADOS_HALLAZGO.abierto,
  abiertoConEvidencia: ESTADOS_HALLAZGO.abierto_evidencia,
  cerrado: ESTADOS_HALLAZGO.cerrado,
  sinDato: ESTADOS_HALLAZGO.sin_dato,

  desde(valor?: string | null): EstadoHallazgoInfo {
    return ESTADOS_HALLAZGO[valor as EstadoHallazgoValor] ?? ESTADOS_HALLAZGO.sin_dato
  },
}

/** Destino que el reconciliador propone para un hallazgo al cargar una auditoría. */
export type DestinoReconciliacionValor =
  | 'persiste'
  | 'nuevo'
  | 'reincidencia'
  | 'candidato_cierre'
  | 'no_verificado'
  | 'ausente_corte'
  | 'revision'
  | 'conflicto'

export interface DestinoReconciliacionInfo {
  valor: DestinoReconciliacionValor
  etiqueta: string
  color: string
  /** Los que no se aplican solos: alguien tiene que decidir. */
  exigeConfirmacion: boolean
}

const DESTINOS_RECONCILIACION: Record<DestinoReconciliacionValor, DestinoReconciliacionInfo> = {
  persiste: { valor: 'persiste', etiqueta: 'Persiste', color: '#2E3D8F', exigeConfirmacion: false },
  nuevo: { valor: 'nuevo', etiqueta: 'Nuevo', color: '#2E3D8F', exigeConfirmacion: false },
  reincidencia: {
    valor: 'reincidencia',
    etiqueta: 'Reincidencia',
    color: '#8A6410',
    exigeConfirmacion: true,
  },
  candidato_cierre: {
    valor: 'candidato_cierre',
    etiqueta: 'Candidato a cierre',
    color: '#A32E22',
    exigeConfirmacion: true,
  },
  no_verificado: {
    valor: 'no_verificado',
    etiqueta: 'No verificado',
    color: '#7E867F',
    exigeConfirmacion: false,
  },
  ausente_corte: {
    valor: 'ausente_corte',
    etiqueta: 'Ausente del corte',
    color: '#7E867F',
    exigeConfirmacion: false,
  },
  revision: {
    valor: 'revision',
    etiqueta: 'Requiere revisión',
    color: '#8A6410',
    exigeConfirmacion: true,
  },
  conflicto: { valor: 'conflicto', etiqueta: 'Conflicto', color: '#A32E22', exigeConfirmacion: true },
}

export const DestinoReconciliacion = {
  valores: Object.values(DESTINOS_RECONCILIACION),

  desde(valor?: string | null): DestinoReconciliacionInfo {
    return DESTINOS_RECONCILIACION[valor as DestinoReconciliacionValor] ?? DESTINOS_RECONCILIACION.revision
  },

  /** Orden fijo en la pantalla de confirmar cruce: lo que exige decisión va primero. */
  ordenConfirmacion: [
    DESTINOS_RECONCILIACION.conflicto,
    DESTINOS_RECONCILIACION.candidato_cierre,
    DESTINOS_RECONCILIACION.reincidencia,
    DESTINOS_RECONCILIACION.revision,
    DESTINOS_RECONCILIACION.no_verificado,
    DESTINOS_RECONCILIACION.nuevo,
    DESTINOS_RECONCILIACION.persiste,
  ] as DestinoReconciliacionInfo[],
}

/** Agrupaciones del consolidado: los tres cortes salen del mismo endpoint. */
export interface AgrupacionConsolidadoInfo {
  valor: 'sede' | 'estandar' | 'sede_estandar'
  etiqueta: string
}

export const AgrupacionConsolidado = {
  sede: { valor: 'sede', etiqueta: 'Por sede' } as AgrupacionConsolidadoInfo,
  estandar: { valor: 'estandar', etiqueta: 'Por estándar' } as AgrupacionConsolidadoInfo,
  sedeEstandar: { valor: 'sede_estandar', etiqueta: 'Sede × estándar' } as AgrupacionConsolidadoInfo,
}
