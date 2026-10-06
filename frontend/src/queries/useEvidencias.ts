import { useQuery } from '@tanstack/vue-query'

import * as evidenciasRepository from '@/data/evidencias.repository'

/** Todas las fotos de una vez: son unas pocas centenas y el filtro por sede es local e instantáneo. */
export function useEvidenciasQuery() {
  return useQuery({
    queryKey: ['evidencias'],
    queryFn: () => evidenciasRepository.evidencias(),
  })
}
