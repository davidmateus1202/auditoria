<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'

import { ApiError } from '@/core/http/apiClient'
import { Formato } from '@/core/format'
import { guardarArchivo } from '@/core/downloads'
import { AgrupacionConsolidado, type AgrupacionConsolidadoInfo } from '@/domain/enums'
import { filaConsolidadoTotal } from '@/domain/models'
import { useEstandaresQuery } from '@/queries/useCatalogos'
import { useConsolidadoQuery, useExportarConsolidadoMutation } from '@/queries/useConsolidado'
import { useUiStore } from '@/stores/ui.store'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import Icon from '@/components/ui/Icon.vue'
import IconButton from '@/components/ui/IconButton.vue'
import Notice from '@/components/ui/Notice.vue'
import Panel from '@/components/ui/Panel.vue'
import SectionHeading from '@/components/ui/SectionHeading.vue'
import StackedStatusBar from '@/components/ui/StackedStatusBar.vue'
import StatusLegend from '@/components/ui/StatusLegend.vue'
import HorizontalBar from '@/components/ui/HorizontalBar.vue'
import PageHeader from '@/components/layout/PageHeader.vue'

/** El consolidado, con los tres cortes que pidió el usuario. Mirror de
 * PantallaConsolidado (pantallas/consolidado.dart): son el mismo dato con
 * distinta agrupación, lo único que cambia entre pestañas es cómo se dibuja. */
const router = useRouter()
const ui = useUiStore()

const consolidadoQuery = useConsolidadoQuery()
const estandaresQuery = useEstandaresQuery()
const exportarMutation = useExportarConsolidadoMutation()

const pestana = ref<AgrupacionConsolidadoInfo>(AgrupacionConsolidado.sede)

const abreviaturas: Record<string, string> = {
  'Talento humano': 'Talento',
  Infraestructura: 'Infra.',
  Dotación: 'Dotación',
  'Medicamentos, dispositivos médicos e insumos': 'Medicam.',
  'Procesos prioritarios': 'Procesos',
  'Historia clínica y registros': 'H. clínica',
  Interdependencia: 'Interdep.',
  'Servicios habilitados no prestados': 'No prest.',
}

function abreviar(nombre: string): string {
  return abreviaturas[nombre] ?? (nombre.length > 10 ? `${nombre.slice(0, 9)}.` : nombre)
}

const columnasMatriz = computed(() => {
  const c = consolidadoQuery.data.value
  const estandares = estandaresQuery.data.value ?? []
  if (!c) return []
  return estandares.filter((e) => c.sedePorEstandar.some((f) => (f.estandares[e.codigo] ?? 0) > 0))
})

const totalesMatriz = computed(() => {
  const c = consolidadoQuery.data.value
  const totales: Record<string, number> = {}
  if (!c) return totales
  for (const e of columnasMatriz.value) {
    totales[e.codigo] = c.sedePorEstandar.reduce((a, f) => a + (f.estandares[e.codigo] ?? 0), 0)
  }
  return totales
})

async function exportar() {
  const periodo = consolidadoQuery.data.value?.periodo
  if (!periodo) return

  try {
    const blob = await exportarMutation.mutateAsync({ periodo })
    guardarArchivo(`consolidado-${periodo}.xlsx`, blob)
    ui.mostrar(`Consolidado de ${Formato.periodo(periodo)} descargado (${Math.round(blob.size / 1024)} KB).`)
  } catch (e) {
    ui.mostrar(`No se pudo exportar: ${e instanceof ApiError ? e.mensaje : e}`, { error: true })
  }
}
</script>

<template>
  <PageHeader titulo="Consolidado">
    <template #acciones>
      <IconButton icono="chart-line" titulo="Serie mensual" @click="router.push({ name: 'serie' })" />
    </template>
    <template #bottom>
      <div class="flex gap-1 border-t border-(--color-regla) px-4 sm:px-6">
        <button
          v-for="a in Object.values(AgrupacionConsolidado)"
          :key="a.valor"
          type="button"
          class="border-b-2 px-3 py-2.5 text-[13.5px] font-medium"
          :class="
            pestana.valor === a.valor
              ? 'border-(--color-sello) text-(--color-sello)'
              : 'border-transparent text-(--color-apagado)'
          "
          @click="pestana = a"
        >
          {{ a.etiqueta }}
        </button>
      </div>
    </template>
  </PageHeader>

  <div v-if="consolidadoQuery.isPending.value" class="flex justify-center py-20 text-(--color-apagado)">Cargando…</div>
  <ErrorState v-else-if="consolidadoQuery.isError.value" :error="consolidadoQuery.error.value" :reintentar="() => consolidadoQuery.refetch()" />

  <template v-else-if="consolidadoQuery.data.value">
    <div
      class="flex items-center gap-2 px-4 py-2.5 text-[12.5px] font-semibold sm:px-6"
      :style="{
        backgroundColor: consolidadoQuery.data.value.corteCerrado ? 'var(--color-superficie-2)' : 'var(--color-alerta-fondo)',
        color: consolidadoQuery.data.value.corteCerrado ? 'var(--color-tinta-2)' : 'var(--color-alerta)',
      }"
    >
      <Icon :name="consolidadoQuery.data.value.corteCerrado ? 'lock' : 'calendar-edit'" :size="15" />
      <span>
        Corte de {{ Formato.periodo(consolidadoQuery.data.value.periodo) }}
        <template v-if="!consolidadoQuery.data.value.corteCerrado"> · abierto, las cifras pueden cambiar</template>
      </span>
      <span class="tabular ml-auto">{{ Formato.numero(consolidadoQuery.data.value.generales.hallazgos) }} hallazgos</span>
    </div>

    <div class="relative mx-auto max-w-4xl p-4 pb-24 sm:px-6 sm:pt-6">
      <!-- Por sede -->
      <div v-if="pestana.valor === 'sede'" class="space-y-2.5">
        <Panel v-for="fila in consolidadoQuery.data.value.porSede" :key="fila.clave">
          <div class="flex items-center justify-between gap-2">
            <p class="text-[15px] font-semibold">{{ fila.nombre }}</p>
            <span class="tabular text-[19px] font-bold">{{ Formato.numero(fila.generales.hallazgos) }}</span>
          </div>
          <div class="mt-2.5"><StackedStatusBar :generales="fila.generales" :altura="8" /></div>
          <div class="mt-2.5 flex gap-5">
            <div>
              <p class="text-[9.5px] font-bold tracking-wide text-(--color-apagado) uppercase">Avance</p>
              <p class="tabular text-[14px] font-bold" style="color: var(--color-bien)">
                {{ Formato.porcentaje(fila.generales.pctAvance) }}
              </p>
            </div>
            <div>
              <p class="text-[9.5px] font-bold tracking-wide text-(--color-apagado) uppercase">Con gestión</p>
              <p class="tabular text-[14px] font-bold" style="color: var(--color-alerta)">
                {{ Formato.porcentaje(fila.generales.pctConGestion) }}
              </p>
            </div>
            <div v-if="fila.mesAntiguoMeses > 0">
              <p class="text-[9.5px] font-bold tracking-wide text-(--color-apagado) uppercase">Más antiguo</p>
              <p class="tabular text-[14px] font-bold text-(--color-tinta-2)">{{ fila.mesAntiguoMeses }} m</p>
            </div>
          </div>
        </Panel>
      </div>

      <!-- Por estándar -->
      <div v-else-if="pestana.valor === 'estandar'">
        <EmptyState
          v-if="consolidadoQuery.data.value.porEstandar.length === 0"
          titulo="Sin datos en este corte"
          detalle="No hay hallazgos registrados para el periodo seleccionado."
        />
        <template v-else>
          <Panel>
            <HorizontalBar
              v-for="fila in consolidadoQuery.data.value.porEstandar"
              :key="fila.clave"
              :rotulo="fila.nombre"
              :valor="fila.generales.hallazgos"
              :maximo="consolidadoQuery.data.value.porEstandar[0].generales.hallazgos"
              :porcentaje="fila.pctDelTotal"
            />
          </Panel>
          <SectionHeading texto="Estado por estándar" class="mt-4" />
          <div class="space-y-2.5">
            <Panel v-for="fila in consolidadoQuery.data.value.porEstandar" :key="fila.clave">
              <div class="flex items-center justify-between gap-2">
                <p class="text-[14px] font-semibold">{{ fila.nombre }}</p>
                <span class="tabular text-[16px] font-bold">{{ Formato.numero(fila.generales.hallazgos) }}</span>
              </div>
              <div class="mt-2"><StackedStatusBar :generales="fila.generales" :altura="7" /></div>
              <div class="mt-2"><StatusLegend :generales="fila.generales" /></div>
            </Panel>
          </div>
        </template>
      </div>

      <!-- Sede × estándar -->
      <div v-else>
        <div v-if="columnasMatriz.length === 0" class="flex justify-center py-12 text-(--color-apagado)">Cargando…</div>
        <template v-else>
          <Panel sin-padding>
            <div class="overflow-x-auto">
              <table class="w-full text-left">
                <thead>
                  <tr class="bg-(--color-superficie-2)">
                    <th class="sticky left-0 bg-(--color-superficie-2) px-3.5 py-3 text-[11.5px] font-bold whitespace-nowrap">
                      Centro de salud
                    </th>
                    <th
                      v-for="e in columnasMatriz"
                      :key="e.codigo"
                      :title="e.nombre"
                      class="px-2.5 py-3 text-right text-[11px] font-bold whitespace-nowrap"
                    >
                      {{ abreviar(e.nombre) }}
                    </th>
                    <th class="px-3.5 py-3 text-right text-[11.5px] font-bold whitespace-nowrap">Total</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="fila in consolidadoQuery.data.value.sedePorEstandar" :key="fila.clave" class="border-t border-(--color-regla)">
                    <td class="sticky left-0 bg-(--color-superficie) px-3.5 py-2.5 text-[12.5px] whitespace-nowrap">
                      {{ fila.nombre }}
                    </td>
                    <td v-for="e in columnasMatriz" :key="e.codigo" class="px-2.5 py-2.5 text-right text-[13px]">
                      <span v-if="(fila.estandares[e.codigo] ?? 0) === 0" class="text-(--color-regla)">—</span>
                      <span
                        v-else
                        class="tabular inline-block rounded-[3px] px-1.5 py-0.5"
                        :class="fila.estandarDominante === e.codigo ? 'font-bold' : ''"
                        :style="
                          fila.estandarDominante === e.codigo
                            ? { backgroundColor: 'var(--color-sello-suave)', color: 'var(--color-sello)' }
                            : {}
                        "
                      >
                        {{ Formato.numero(fila.estandares[e.codigo] ?? 0) }}
                      </span>
                    </td>
                    <td class="tabular px-3.5 py-2.5 text-right text-[13px] font-bold">
                      {{ Formato.numero(filaConsolidadoTotal(fila)) }}
                    </td>
                  </tr>
                  <tr class="border-t border-(--color-regla) bg-(--color-hundido) font-bold">
                    <td class="sticky left-0 bg-(--color-hundido) px-3.5 py-2.5 text-[12px] whitespace-nowrap">TOTAL</td>
                    <td v-for="e in columnasMatriz" :key="e.codigo" class="tabular px-2.5 py-2.5 text-right text-[13px]">
                      {{ Formato.numero(totalesMatriz[e.codigo] ?? 0) }}
                    </td>
                    <td class="tabular px-3.5 py-2.5 text-right text-[13px]">
                      {{ Formato.numero(consolidadoQuery.data.value.generales.hallazgos) }}
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </Panel>
          <Notice
            class="mt-3"
            icono="bulb"
            mensaje="La celda resaltada de cada fila es el estándar donde esa sede concentra más hallazgos."
          />
        </template>
      </div>
    </div>

    <button
      type="button"
      class="fixed right-5 bottom-20 flex items-center gap-2 rounded-full px-5 py-3.5 text-[14px] font-semibold text-white shadow-lg lg:bottom-6"
      style="background-color: var(--color-sello)"
      :disabled="exportarMutation.isPending.value"
      @click="exportar"
    >
      <Icon :name="exportarMutation.isPending.value ? 'refresh' : 'download'" :size="18" />
      {{ exportarMutation.isPending.value ? 'Generando…' : 'Excel' }}
    </button>
  </template>
</template>
