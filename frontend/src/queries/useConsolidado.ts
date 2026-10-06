import { useMutation, useQuery } from '@tanstack/vue-query'

import * as consolidadoRepository from '@/data/consolidado.repository'
import type { FiltroConsolidado } from '@/data/consolidado.repository'

export function useConsolidadoQuery() {
  return useQuery({
    queryKey: ['consolidado'],
    queryFn: () => consolidadoRepository.consolidado(),
  })
}

export function useSerieQuery() {
  return useQuery({
    queryKey: ['serie'],
    queryFn: () => consolidadoRepository.serie(),
  })
}

export function useExportarConsolidadoMutation() {
  return useMutation({
    mutationFn: (filtro: FiltroConsolidado) => consolidadoRepository.exportarConsolidado(filtro),
  })
}
