import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { type Ref, toValue } from 'vue'

import * as auditoriasRepository from '@/data/auditorias.repository'
import { invalidarDatos } from './invalidar'
import type { OpcionesCarga } from '@/data/auditorias.repository'

export function useAuditoriasQuery() {
  return useQuery({
    queryKey: ['auditorias'],
    queryFn: () => auditoriasRepository.auditorias(),
  })
}

export function useReconciliacionQuery(auditoriaId: Ref<number> | number) {
  return useQuery({
    queryKey: ['cruce', auditoriaId],
    queryFn: () => auditoriasRepository.reconciliacionDeAuditoria(toValue(auditoriaId)),
  })
}

export function useCargarAuditoriasMutation() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ archivos, opciones }: { archivos: File[]; opciones?: OpcionesCarga }) =>
      auditoriasRepository.cargarAuditorias(archivos, opciones),
    onSuccess: () => invalidarDatos(queryClient),
  })
}

export function useConfirmarAuditoriaMutation() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({
      auditoriaId,
      decisiones,
    }: {
      auditoriaId: number
      decisiones: Record<number, Record<string, unknown>>
    }) => auditoriasRepository.confirmarAuditoria(auditoriaId, decisiones),
    onSuccess: () => invalidarDatos(queryClient),
  })
}

export function useEliminarAuditoriaMutation() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ auditoriaId, forzar = false }: { auditoriaId: number; forzar?: boolean }) =>
      auditoriasRepository.eliminarAuditoria(auditoriaId, forzar),
    onSuccess: () => invalidarDatos(queryClient),
  })
}

export function useArchivoAuditoriaMutation() {
  return useMutation({
    mutationFn: (auditoriaId: number) => auditoriasRepository.archivoDeAuditoria(auditoriaId),
  })
}
