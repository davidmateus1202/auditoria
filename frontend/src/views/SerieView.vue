<script setup lang="ts">
import { computed } from 'vue'
import { useRouter } from 'vue-router'

import { Formato } from '@/core/format'
import { useSerieQuery } from '@/queries/useConsolidado'
import type { PuntoSerie } from '@/domain/models'
import SerieAvanceChart from '@/components/charts/SerieAvanceChart.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import Panel from '@/components/ui/Panel.vue'
import SectionHeading from '@/components/ui/SectionHeading.vue'
import StackedStatusBar from '@/components/ui/StackedStatusBar.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import Icon from '@/components/ui/Icon.vue'
import PageHeader from '@/components/layout/PageHeader.vue'

/** Evolución mes a mes. Mirror de PantallaSerie (pantallas/serie.dart). */
const router = useRouter()
const serieQuery = useSerieQuery()

interface FilaMes {
  punto: PuntoSerie
  cambioCerrados: number | null
}

// Más reciente primero, con el delta de cerrados ya calculado contra el mes
// anterior (en orden cronológico), igual que _FilaMes en serie.dart.
const filasMesAMes = computed<FilaMes[]>(() => {
  const puntos = serieQuery.data.value ?? []
  return puntos
    .map((punto, i) => ({
      punto,
      cambioCerrados: i === 0 ? null : punto.generales.cerrados - puntos[i - 1].generales.cerrados,
    }))
    .reverse()
})
</script>

<template>
  <PageHeader titulo="Serie mensual" con-volver @volver="router.back()">
    <template #volver-icono><Icon name="arrow-left" :size="19" /></template>
  </PageHeader>

  <div v-if="serieQuery.isPending.value" class="flex justify-center py-20 text-(--color-apagado)">Cargando…</div>
  <ErrorState v-else-if="serieQuery.isError.value" :error="serieQuery.error.value" :reintentar="() => serieQuery.refetch()" />

  <EmptyState
    v-else-if="serieQuery.data.value && serieQuery.data.value.length < 2"
    icono="timeline"
    titulo="Todavía no hay serie"
    :detalle="
      serieQuery.data.value.length === 0
        ? 'La serie aparece cuando se cierre el primer corte mensual.'
        : 'Con un solo corte no hay evolución que mostrar. Cierre el mes siguiente y la comparación aparecerá aquí.'
    "
  />

  <div v-else-if="serieQuery.data.value" class="mx-auto max-w-3xl space-y-5 p-4 pb-10 sm:p-6">
    <div>
      <SectionHeading texto="Avance acumulado" />
      <Panel>
        <SerieAvanceChart :puntos="serieQuery.data.value" />
      </Panel>
    </div>

    <div>
      <SectionHeading texto="Mes a mes" />
      <Panel sin-padding>
        <div
          v-for="(fila, i) in filasMesAMes"
          :key="fila.punto.periodo"
          class="px-3.5 py-3"
          :class="i > 0 ? 'border-t border-(--color-regla)' : ''"
        >
          <div class="flex items-center gap-2">
            <p class="flex-1 text-[14px] font-semibold">{{ Formato.periodo(fila.punto.periodo) }}</p>
            <StatusBadge v-if="!fila.punto.cerrado" texto="Abierto" color="var(--color-alerta)" fondo="var(--color-alerta-fondo)" />
            <span class="tabular text-[15px] font-bold" style="color: var(--color-bien)">
              {{ Formato.porcentaje(fila.punto.generales.pctAvance) }}
            </span>
          </div>
          <div class="mt-2"><StackedStatusBar :generales="fila.punto.generales" :altura="7" /></div>
          <div class="mt-1.5 flex items-center gap-2 text-[11.5px] text-(--color-apagado)">
            <span>
              {{ Formato.numero(fila.punto.generales.hallazgos) }} hallazgos ·
              {{ Formato.numero(fila.punto.generales.cerrados) }} cerrados
            </span>
            <span
              v-if="fila.cambioCerrados"
              class="font-semibold"
              :style="{ color: fila.cambioCerrados > 0 ? 'var(--color-bien)' : 'var(--color-critico)' }"
            >
              {{ fila.cambioCerrados > 0 ? `+${fila.cambioCerrados}` : fila.cambioCerrados }} en el mes
            </span>
          </div>
        </div>
      </Panel>
    </div>
  </div>
</template>
