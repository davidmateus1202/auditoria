import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'

import * as catalogosRepository from '@/data/catalogos.repository'
import { invalidarDatos } from './invalidar'

export function useSedesQuery() {
  return useQuery({
    queryKey: ['sedes'],
    queryFn: () => catalogosRepository.sedes(),
  })
}

export function useEstandaresQuery() {
  return useQuery({
    queryKey: ['estandares'],
    queryFn: () => catalogosRepository.estandares(),
    staleTime: 10 * 60 * 1000,
  })
}

/** Registra una sede que el catálogo todavía no conoce. Útil a mitad de una
 * carga que no pudo identificarla, no solo en una pantalla de administración. */
export function useCrearSedeMutation() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ codigo, nombre }: { codigo: string; nombre: string }) =>
      catalogosRepository.crearSede(codigo, nombre),
    onSuccess: () => invalidarDatos(queryClient),
  })
}
