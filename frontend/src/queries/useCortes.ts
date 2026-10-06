import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { type Ref, toValue } from 'vue'

import * as cortesRepository from '@/data/cortes.repository'
import { invalidarDatos } from './invalidar'
import type { ActualizacionHallazgoCorte, FiltroHallazgosCorte } from '@/data/cortes.repository'

export function useCortesQuery() {
  return useQuery({
    queryKey: ['cortes'],
    queryFn: () => cortesRepository.cortes(),
  })
}

export function useCorteQuery(periodo: Ref<string> | string, enabled: Ref<boolean> | boolean = true) {
  return useQuery({
    queryKey: ['corte', periodo],
    queryFn: () => cortesRepository.corte(toValue(periodo)),
    enabled,
  })
}

export function useHallazgosCorteQuery(periodo: Ref<string> | string, filtro: Ref<FiltroHallazgosCorte> | FiltroHallazgosCorte) {
  return useQuery({
    queryKey: ['hallazgosCorte', periodo, filtro],
    queryFn: () => cortesRepository.hallazgosDelCorte(toValue(periodo), toValue(filtro)),
  })
}

/** Edita a mano la foto de un hallazgo en el corte abierto. Igual que
 * cambiar el estado desde el detalle, mueve el hallazgo y lo que alimenta al
 * consolidado y a su exportación en Excel. */
export function useActualizarEnCorteMutation() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({
      periodo,
      hallazgoId,
      cambio,
    }: {
      periodo: string
      hallazgoId: number
      cambio: ActualizacionHallazgoCorte
    }) => cortesRepository.actualizarEnCorte(periodo, hallazgoId, cambio),
    onSuccess: () => invalidarDatos(queryClient),
  })
}

export function useAbrirCorteMutation() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (periodo: string) => cortesRepository.abrirCorte(periodo),
    onSuccess: () => invalidarDatos(queryClient),
  })
}

export function useCerrarCorteMutation() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (periodo: string) => cortesRepository.cerrarCorte(periodo),
    onSuccess: () => invalidarDatos(queryClient),
  })
}

export function useSubirMatrizMutation() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ periodo, archivo }: { periodo: string; archivo: File }) =>
      cortesRepository.subirMatriz(periodo, archivo),
    onSuccess: () => invalidarDatos(queryClient),
  })
}

/** La descarga no tiene estado que cachear, pero se modela como mutation
 * para reusar sus banderas `isPending`/`isError` en la pantalla. */
export function useDescargarMatrizMutation() {
  return useMutation({
    mutationFn: (periodo: string) => cortesRepository.descargarMatriz(periodo),
  })
}
