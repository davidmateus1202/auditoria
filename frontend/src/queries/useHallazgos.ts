import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { type Ref, toValue } from 'vue'

import * as hallazgosRepository from '@/data/hallazgos.repository'
import { invalidarDatos } from './invalidar'
import type { CambioEstadoHallazgo, FiltroHallazgos, NuevoHallazgo } from '@/data/hallazgos.repository'

export function useHallazgosQuery(filtro: Ref<FiltroHallazgos> | FiltroHallazgos) {
  return useQuery({
    queryKey: ['hallazgos', filtro],
    queryFn: () => hallazgosRepository.listar(toValue(filtro)),
  })
}

export function useHallazgoQuery(hallazgoId: Ref<number> | number) {
  return useQuery({
    queryKey: ['hallazgo', hallazgoId],
    queryFn: () => hallazgosRepository.obtener(toValue(hallazgoId)),
  })
}

export function useLineaTiempoQuery(hallazgoId: Ref<number> | number) {
  return useQuery({
    queryKey: ['lineaTiempo', hallazgoId],
    queryFn: () => hallazgosRepository.lineaTiempo(toValue(hallazgoId)),
  })
}

/** Al guardar un seguimiento cambian las cifras de hallazgos, el tablero y el
 * consolidado: todo lo que depende de ese número se invalida junto. */
export function useCambiarEstadoMutation() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ hallazgoId, cambio }: { hallazgoId: number; cambio: CambioEstadoHallazgo }) =>
      hallazgosRepository.cambiarEstado(hallazgoId, cambio),
    onSuccess: () => invalidarDatos(queryClient),
  })
}

/** Registrar un hallazgo nuevo a mano entra directo al corte abierto (si lo
 * hay), así que afecta lo mismo que una carga de Excel: la lista del corte,
 * Hallazgos y el consolidado. */
export function useCrearHallazgoMutation() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (nuevo: NuevoHallazgo) => hallazgosRepository.crear(nuevo),
    onSuccess: () => invalidarDatos(queryClient),
  })
}
