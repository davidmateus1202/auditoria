<script setup lang="ts">
import { computed } from 'vue'
import { useRouter } from 'vue-router'

import { Formato } from '@/core/format'
import { useEstandaresQuery } from '@/queries/useCatalogos'
import { useConsolidadoQuery } from '@/queries/useConsolidado'
import AppButton from '@/components/ui/AppButton.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import HorizontalBar from '@/components/ui/HorizontalBar.vue'
import IconButton from '@/components/ui/IconButton.vue'
import Notice from '@/components/ui/Notice.vue'
import Panel from '@/components/ui/Panel.vue'
import SectionHeading from '@/components/ui/SectionHeading.vue'
import StackedStatusBar from '@/components/ui/StackedStatusBar.vue'
import StatCard from '@/components/ui/StatCard.vue'
import StatusLegend from '@/components/ui/StatusLegend.vue'
import PageHeader from '@/components/layout/PageHeader.vue'

/** Tablero del municipio. Mirror de PantallaInicio (pantallas/inicio.dart):
 * abre mostrando lo que hay que decidir, con las cifras del corte arriba
 * porque son la respuesta a la pregunta que trae quien abre la app. */
const router = useRouter()
const consolidadoQuery = useConsolidadoQuery()
const estandaresQuery = useEstandaresQuery()

const nombresEstandar = computed(() => {
  const mapa = new Map<string, string>()
  for (const e of estandaresQuery.data.value ?? []) mapa.set(e.codigo, e.nombre)
  return mapa
})

function irAHallazgos(query: Record<string, string>) {
  router.push({ name: 'hallazgos', query })
}
</script>

<template>
  <PageHeader titulo="Auditoría SUH">
    <template #acciones>
      <IconButton icono="upload" titulo="Cargar auditoría" @click="router.push({ name: 'cargar-auditoria' })" />
      <IconButton icono="refresh" titulo="Actualizar" :cargando="consolidadoQuery.isFetching.value" @click="consolidadoQuery.refetch()" />
    </template>
  </PageHeader>

  <div v-if="consolidadoQuery.isPending.value" class="flex justify-center py-20 text-(--color-apagado)">
    Cargando…
  </div>

  <ErrorState
    v-else-if="consolidadoQuery.isError.value"
    :error="consolidadoQuery.error.value"
    :reintentar="() => consolidadoQuery.refetch()"
  />

  <template v-else-if="consolidadoQuery.data.value">
    <EmptyState
      v-if="consolidadoQuery.data.value.generales.hallazgos === 0"
      icono="folder-off"
      titulo="Todavía no hay hallazgos"
      detalle="Suba la autoevaluación de una sede para empezar a ver las cifras del municipio."
    >
      <template #accion>
        <AppButton icono="upload" @click="router.push({ name: 'cargar-auditoria' })">Cargar auditoría</AppButton>
      </template>
    </EmptyState>

    <div v-else class="mx-auto max-w-5xl space-y-5 p-4 pb-10 sm:p-6">
      <Notice
        v-if="!consolidadoQuery.data.value.corteCerrado"
        icono="calendar-edit"
        color="var(--color-alerta)"
        :mensaje="`Corte de ${Formato.periodo(consolidadoQuery.data.value.periodo)} todavía abierto: las cifras pueden cambiar hasta que se cierre el mes.`"
      />
      <div v-else class="flex items-center gap-2 text-[13px] font-semibold text-(--color-tinta-2)">
        <span>Corte de {{ Formato.periodo(consolidadoQuery.data.value.periodo) }}</span>
      </div>

      <Panel>
        <div class="grid grid-cols-3 gap-4">
          <StatCard
            :valor="Formato.numero(consolidadoQuery.data.value.generales.hallazgos)"
            rotulo="Hallazgos&#10;del corte"
          />
          <StatCard
            :valor="Formato.porcentaje(consolidadoQuery.data.value.generales.pctAvance)"
            rotulo="Avance&#10;cerrados"
            color="var(--color-bien)"
          />
          <StatCard
            :valor="Formato.numero(consolidadoQuery.data.value.generales.abiertos)"
            rotulo="Abiertos&#10;sin gestión"
            color="var(--color-critico)"
          />
        </div>
        <div class="mt-5">
          <StackedStatusBar :generales="consolidadoQuery.data.value.generales" :altura="10" />
        </div>
        <div class="mt-3">
          <StatusLegend :generales="consolidadoQuery.data.value.generales" />
        </div>
      </Panel>

      <div v-if="consolidadoQuery.data.value.porEstandar.length > 0">
        <SectionHeading texto="Dónde se concentran" />
        <Panel>
          <HorizontalBar
            v-for="fila in consolidadoQuery.data.value.porEstandar"
            :key="fila.clave"
            :rotulo="nombresEstandar.get(fila.clave) ?? fila.nombre"
            :valor="fila.generales.hallazgos"
            :maximo="consolidadoQuery.data.value.porEstandar[0].generales.hallazgos"
            :porcentaje="fila.pctDelTotal"
            clicable
            @click="irAHallazgos({ estandar: fila.clave })"
          />
        </Panel>
        <p
          v-if="consolidadoQuery.data.value.porEstandar.length >= 2"
          class="mt-2.5 px-0.5 text-[12.5px] leading-relaxed text-(--color-apagado)"
        >
          {{
            Formato.porcentaje(
              (consolidadoQuery.data.value.porEstandar[0].pctDelTotal ?? 0) +
                (consolidadoQuery.data.value.porEstandar[1].pctDelTotal ?? 0),
            )
          }}
          de los hallazgos son de
          {{ consolidadoQuery.data.value.porEstandar[0].nombre.toLowerCase() }} y
          {{ consolidadoQuery.data.value.porEstandar[1].nombre.toLowerCase() }}.
        </p>
      </div>

      <div>
        <SectionHeading texto="Sedes con más abiertos">
          <template #accion>
            <button
              type="button"
              class="text-[13px] font-medium text-(--color-sello)"
              @click="router.push({ name: 'hallazgos' })"
            >
              Ver todos
            </button>
          </template>
        </SectionHeading>
        <Panel sin-padding>
          <button
            v-for="(fila, i) in consolidadoQuery.data.value.porSede"
            :key="fila.clave"
            type="button"
            class="flex w-full items-center gap-3.5 px-3.5 py-3 text-left hover:bg-(--color-hundido)/40"
            :class="i > 0 ? 'border-t border-(--color-regla)' : ''"
            @click="irAHallazgos({ sede: fila.clave })"
          >
            <div class="min-w-0 flex-1">
              <p class="truncate text-[14px] font-semibold">{{ fila.nombre }}</p>
              <div class="mt-1.5">
                <StackedStatusBar :generales="fila.generales" :altura="6" />
              </div>
            </div>
            <div class="shrink-0 text-right">
              <p class="tabular text-[17px] font-bold">{{ Formato.numero(fila.generales.hallazgos) }}</p>
              <p class="text-[11px] text-(--color-apagado)">
                {{ Formato.porcentaje(fila.generales.pctAvance) }} cerrado
              </p>
            </div>
          </button>
        </Panel>
      </div>
    </div>
  </template>
</template>
