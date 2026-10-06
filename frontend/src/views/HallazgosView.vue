<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import * as catalogosRepository from '@/data/catalogos.repository'
import { EstadoHallazgo } from '@/domain/enums'
import { useEstandaresQuery, useSedesQuery } from '@/queries/useCatalogos'
import { useHallazgosQuery } from '@/queries/useHallazgos'
import { useFiltroHallazgosStore } from '@/stores/filtroHallazgos.store'
import Chip from '@/components/ui/Chip.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import Icon from '@/components/ui/Icon.vue'
import SelectorSheet, { type OpcionSelector } from '@/components/ui/SelectorSheet.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import PageHeader from '@/components/layout/PageHeader.vue'

/** Mirror de PantallaHallazgos (pantallas/hallazgos.dart). */
const router = useRouter()
const route = useRoute()
const filtro = useFiltroHallazgosStore()

const sedesQuery = useSedesQuery()
const estandaresQuery = useEstandaresQuery()

const textoBuscar = ref(filtro.buscar)

function alBuscar() {
  filtro.buscar = textoBuscar.value.trim()
}

function limpiarBusqueda() {
  textoBuscar.value = ''
  filtro.buscar = ''
}

// Llegar desde el tablero con un filtro ya puesto: la pantalla continúa la
// pregunta que traía el usuario en vez de empezar de cero.
onMounted(async () => {
  const sedeCodigo = typeof route.query.sede === 'string' ? route.query.sede : null
  const estandarCodigo = typeof route.query.estandar === 'string' ? route.query.estandar : null
  if (!sedeCodigo && !estandarCodigo) return

  filtro.reiniciar()
  if (estandarCodigo) filtro.estandar = estandarCodigo

  if (sedeCodigo) {
    const sedes = await catalogosRepository.sedes()
    const sede = sedes.find((s) => s.codigo === sedeCodigo) ?? null
    filtro.establecerSede(sede?.id ?? null, sede?.codigo ?? null)
  }

  router.replace({ name: 'hallazgos' })
})

const filtroQuery = computed(() => ({
  sedeId: filtro.sedeId,
  estandar: filtro.estandar,
  estado: filtro.estado,
  buscar: filtro.buscar,
  soloVigentes: filtro.soloVigentes,
}))

const hallazgosQuery = useHallazgosQuery(filtroQuery)

const opcionesSede = computed<OpcionSelector[]>(
  () => (sedesQuery.data.value ?? []).map((s) => ({ valor: s.codigo, etiqueta: s.nombre })),
)
const opcionesEstandar = computed<OpcionSelector[]>(
  () => (estandaresQuery.data.value ?? []).map((e) => ({ valor: e.codigo, etiqueta: e.nombre })),
)
const opcionesEstado = computed<OpcionSelector[]>(
  () => EstadoHallazgo.valores.map((e) => ({ valor: e.valor, etiqueta: e.etiqueta })),
)

function alElegirSede(codigo: string | number | null) {
  const sede = (sedesQuery.data.value ?? []).find((s) => s.codigo === codigo) ?? null
  filtro.establecerSede(sede?.id ?? null, sede?.codigo ?? null)
}

function quitarFiltros() {
  textoBuscar.value = ''
  filtro.reiniciar()
}

watch(
  () => filtro.buscar,
  (nuevo) => {
    textoBuscar.value = nuevo
  },
)
</script>

<template>
  <PageHeader titulo="Hallazgos">
    <template #bottom>
      <div class="flex flex-col gap-2.5 px-3 pb-2.5">
        <div class="flex h-11 items-center gap-2 rounded border border-(--color-regla) bg-(--color-superficie) px-3">
          <Icon name="search" :size="18" class="text-(--color-apagado)" />
          <input
            v-model="textoBuscar"
            type="search"
            placeholder="Buscar en el texto del hallazgo"
            class="flex-1 bg-transparent text-[14px] outline-none"
            @keyup.enter="alBuscar"
          />
          <button v-if="textoBuscar" type="button" @click="limpiarBusqueda">
            <Icon name="close" :size="16" class="text-(--color-apagado)" />
          </button>
        </div>
        <div class="flex gap-2 overflow-x-auto pb-1">
          <SelectorSheet
            etiqueta="Sede"
            :opciones="opcionesSede"
            :model-value="filtro.sedeCodigo"
            @update:model-value="alElegirSede"
          />
          <SelectorSheet etiqueta="Estándar" :opciones="opcionesEstandar" v-model="filtro.estandar" />
          <SelectorSheet etiqueta="Estado" :opciones="opcionesEstado" v-model="filtro.estado" />
          <Chip :selected="filtro.soloVigentes" :icono="filtro.soloVigentes ? 'check' : undefined" @click="filtro.soloVigentes = !filtro.soloVigentes">
            Solo vigentes
          </Chip>
        </div>
      </div>
    </template>
  </PageHeader>

  <div v-if="hallazgosQuery.isPending.value" class="flex justify-center py-20 text-(--color-apagado)">Cargando…</div>

  <ErrorState
    v-else-if="hallazgosQuery.isError.value"
    :error="hallazgosQuery.error.value"
    :reintentar="() => hallazgosQuery.refetch()"
  />

  <EmptyState
    v-else-if="hallazgosQuery.data.value && hallazgosQuery.data.value.datos.length === 0"
    icono="search"
    titulo="Ningún hallazgo con estos filtros"
    :detalle="
      filtro.activos > 0
        ? `Pruebe quitando alguno de los ${filtro.activos} filtros activos.`
        : 'Todavía no hay hallazgos cargados en el sistema.'
    "
  >
    <template v-if="filtro.activos > 0" #accion>
      <button type="button" class="text-[13.5px] font-medium text-(--color-sello)" @click="quitarFiltros">
        Quitar filtros
      </button>
    </template>
  </EmptyState>

  <template v-else-if="hallazgosQuery.data.value">
    <div class="bg-(--color-superficie-2) px-4 py-2.5 text-[12px] font-semibold text-(--color-tinta-2) sm:px-6">
      {{ hallazgosQuery.data.value.total }} {{ hallazgosQuery.data.value.total === 1 ? 'hallazgo' : 'hallazgos' }}
      <template v-if="hallazgosQuery.data.value.total > hallazgosQuery.data.value.datos.length">
        · mostrando {{ hallazgosQuery.data.value.datos.length }}
      </template>
    </div>

    <button
      v-for="hallazgo in hallazgosQuery.data.value.datos"
      :key="hallazgo.id"
      type="button"
      class="flex w-full items-start gap-3 border-b border-(--color-regla) bg-(--color-superficie) px-4 py-3.5 text-left hover:bg-(--color-hundido)/40 sm:px-6"
      @click="router.push({ name: 'hallazgo-detalle', params: { id: hallazgo.id } })"
    >
      <span class="mt-0.5 h-11 w-[3px] shrink-0" :style="{ backgroundColor: hallazgo.estado.color }" />
      <div class="min-w-0 flex-1">
        <div class="flex items-start justify-between gap-2">
          <p class="truncate text-[11.5px] font-semibold tracking-wide text-(--color-apagado)">
            {{ hallazgo.sedeNombre }} · {{ hallazgo.nombreEstandar }}
          </p>
          <StatusBadge :texto="hallazgo.estado.etiqueta" :color="hallazgo.estado.color" :fondo="hallazgo.estado.fondo" />
        </div>
        <p class="mt-1 line-clamp-3 text-[13.5px] leading-snug">{{ hallazgo.descripcion }}</p>
      </div>
    </button>
  </template>
</template>
