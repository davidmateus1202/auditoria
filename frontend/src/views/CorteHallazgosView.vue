<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import { ApiError } from '@/core/http/apiClient'
import { EstadoHallazgo, type EstadoHallazgoValor } from '@/domain/enums'
import { corteAbierto } from '@/domain/models'
import { useEstandaresQuery, useSedesQuery } from '@/queries/useCatalogos'
import { useActualizarEnCorteMutation, useCorteQuery, useHallazgosCorteQuery } from '@/queries/useCortes'
import { useCrearHallazgoMutation } from '@/queries/useHallazgos'
import { useUiStore } from '@/stores/ui.store'
import type { HallazgoDeCorte } from '@/data/cortes.repository'
import AppButton from '@/components/ui/AppButton.vue'
import AppInput from '@/components/ui/AppInput.vue'
import AppTextarea from '@/components/ui/AppTextarea.vue'
import Chip from '@/components/ui/Chip.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import Icon from '@/components/ui/Icon.vue'
import IconButton from '@/components/ui/IconButton.vue'
import Modal from '@/components/ui/Modal.vue'
import Notice from '@/components/ui/Notice.vue'
import SelectorSheet, { type OpcionSelector } from '@/components/ui/SelectorSheet.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import PageHeader from '@/components/layout/PageHeader.vue'

/**
 * Editar a mano, hallazgo por hallazgo, la foto del corte abierto: la
 * alternativa dentro del navegador al ida y vuelta con Excel. Cambia lo
 * mismo que alimenta al consolidado y a su exportación — a diferencia de
 * «Registrar seguimiento» en el detalle del hallazgo, que solo toca el
 * estado en vivo.
 */
const route = useRoute()
const router = useRouter()
const ui = useUiStore()

const periodo = computed(() => String(route.params.periodo))

const corteQuery = useCorteQuery(periodo)
const sedesQuery = useSedesQuery()
const estandaresQuery = useEstandaresQuery()

const soloEditable = computed(() => corteQuery.data.value ? corteAbierto(corteQuery.data.value) : false)

// ── Filtros ────────────────────────────────────────────────────────────────
const textoBuscar = ref('')
const buscarAplicado = ref('')
const sedeId = ref<number | null>(null)
const sedeCodigo = ref<string | null>(null)
const estandar = ref<string | null>(null)
const estado = ref<EstadoHallazgoValor | null>(null)

const filtro = computed(() => ({
  sedeId: sedeId.value,
  estandar: estandar.value,
  estado: estado.value,
  buscar: buscarAplicado.value,
}))

const activosCount = computed(
  () => [sedeId.value !== null, estandar.value !== null, estado.value !== null, buscarAplicado.value !== ''].filter(
    Boolean,
  ).length,
)

function quitarFiltros() {
  textoBuscar.value = ''
  buscarAplicado.value = ''
  sedeId.value = null
  sedeCodigo.value = null
  estandar.value = null
  estado.value = null
}

function alElegirSede(codigo: string | number | null) {
  const sede = (sedesQuery.data.value ?? []).find((s) => s.codigo === codigo) ?? null
  sedeId.value = sede?.id ?? null
  sedeCodigo.value = sede?.codigo ?? null
}

const opcionesSede = computed<OpcionSelector[]>(
  () => (sedesQuery.data.value ?? []).map((s) => ({ valor: s.codigo, etiqueta: s.nombre })),
)
const opcionesEstandar = computed<OpcionSelector[]>(
  () => (estandaresQuery.data.value ?? []).map((e) => ({ valor: e.codigo, etiqueta: e.nombre })),
)
const opcionesEstado = computed<OpcionSelector[]>(
  () => EstadoHallazgo.valores.map((e) => ({ valor: e.valor, etiqueta: e.etiqueta })),
)

const hallazgosQuery = useHallazgosCorteQuery(periodo, filtro)

// ── Edición ──────────────────────────────────────────────────────────────
const actualizarMutation = useActualizarEnCorteMutation()

const formulario = ref<{
  abierto: boolean
  hallazgo: HallazgoDeCorte | null
  estado: EstadoHallazgoValor
  accion: string
  responsable: string
  evidencia: string
  guardando: boolean
  error: string | null
}>({
  abierto: false,
  hallazgo: null,
  estado: 'abierto',
  accion: '',
  responsable: '',
  evidencia: '',
  guardando: false,
  error: null,
})

const opcionesEstadoFormulario = EstadoHallazgo.valores.filter((e) => e.valor !== 'sin_dato')
const esCierre = computed(() => formulario.value.estado === 'cerrado')

function abrirEdicion(hallazgo: HallazgoDeCorte) {
  if (!soloEditable.value) return

  formulario.value = {
    abierto: true,
    hallazgo,
    estado: hallazgo.estado === 'sin_dato' ? 'abierto' : hallazgo.estado,
    accion: hallazgo.accionPropuesta ?? '',
    responsable: hallazgo.responsable ?? '',
    evidencia: hallazgo.evidencia ?? '',
    guardando: false,
    error: null,
  }
}

async function guardar() {
  const hallazgo = formulario.value.hallazgo
  if (!hallazgo) return


  formulario.value.guardando = true
  formulario.value.error = null

  try {
    await actualizarMutation.mutateAsync({
      periodo: periodo.value,
      hallazgoId: hallazgo.hallazgoId,
      cambio: {
        estado: formulario.value.estado,
        accionPropuesta: formulario.value.accion.trim(),
        responsable: formulario.value.responsable.trim(),
        evidencia: formulario.value.evidencia.trim(),
      },
    })
    formulario.value.abierto = false
    ui.mostrar('Hallazgo actualizado en el corte.')
  } catch (e) {
    formulario.value.error = e instanceof ApiError ? e.mensaje : `No se pudo guardar: ${e}`
  } finally {
    formulario.value.guardando = false
  }
}

// ── Agregar hallazgo a mano, sin subir ningún archivo ──────────────────────
const crearMutation = useCrearHallazgoMutation()

const nuevo = ref<{
  abierto: boolean
  sedeId: number | null
  sedeCodigo: string | null
  estandar: string | null
  descripcion: string
  estado: EstadoHallazgoValor
  accion: string
  responsable: string
  evidencia: string
  guardando: boolean
  error: string | null
}>({
  abierto: false,
  sedeId: null,
  sedeCodigo: null,
  estandar: null,
  descripcion: '',
  estado: 'abierto',
  accion: '',
  responsable: '',
  evidencia: '',
  guardando: false,
  error: null,
})

const esCierreNuevo = computed(() => nuevo.value.estado === 'cerrado')

function abrirCreacion() {
  nuevo.value = {
    abierto: true,
    // Si ya hay un filtro de sede/estándar puesto, se hereda: lo más común
    // es agregar el hallazgo que faltó justo en lo que se está revisando.
    sedeId: sedeId.value,
    sedeCodigo: sedeCodigo.value,
    estandar: estandar.value,
    descripcion: '',
    estado: 'abierto',
    accion: '',
    responsable: '',
    evidencia: '',
    guardando: false,
    error: null,
  }
}

function alElegirSedeNuevo(codigo: string | number | null) {
  const sede = (sedesQuery.data.value ?? []).find((s) => s.codigo === codigo) ?? null
  nuevo.value.sedeId = sede?.id ?? null
  nuevo.value.sedeCodigo = sede?.codigo ?? null
}

async function guardarNuevo() {
  if (nuevo.value.sedeId === null) {
    nuevo.value.error = 'Elija la sede del hallazgo.'
    return
  }
  if (nuevo.value.estandar === null) {
    nuevo.value.error = 'Elija el estándar del hallazgo.'
    return
  }
  if (nuevo.value.descripcion.trim().length < 10) {
    nuevo.value.error = 'Describa el hallazgo con al menos 10 caracteres.'
    return
  }

  nuevo.value.guardando = true
  nuevo.value.error = null

  try {
    await crearMutation.mutateAsync({
      sedeId: nuevo.value.sedeId,
      estandarCodigo: nuevo.value.estandar,
      descripcion: nuevo.value.descripcion.trim(),
      estado: nuevo.value.estado,
      accionPropuesta: nuevo.value.accion.trim(),
      responsable: nuevo.value.responsable.trim(),
      evidencia: nuevo.value.evidencia.trim(),
    })
    nuevo.value.abierto = false
    ui.mostrar('Hallazgo agregado.')
  } catch (e) {
    nuevo.value.error = e instanceof ApiError ? e.mensaje : `No se pudo guardar: ${e}`
  } finally {
    nuevo.value.guardando = false
  }
}
</script>

<template>
  <PageHeader :titulo="`Hallazgos del corte`" con-volver @volver="router.back()">
    <template #volver-icono><Icon name="arrow-left" :size="19" /></template>

    <template v-if="soloEditable" #acciones>
      <IconButton icono="plus" titulo="Agregar hallazgo" @click="abrirCreacion" />
    </template>

    <template #bottom>
      <div class="flex flex-col gap-2.5 px-3 pb-2.5">
        <div class="flex h-11 items-center gap-2 rounded border border-(--color-regla) bg-(--color-superficie) px-3">
          <Icon name="search" :size="18" class="text-(--color-apagado)" />
          <input
            v-model="textoBuscar"
            type="search"
            placeholder="Buscar en el texto del hallazgo"
            class="flex-1 bg-transparent text-[14px] outline-none"
            @keyup.enter="buscarAplicado = textoBuscar.trim()"
          />
          <button v-if="textoBuscar" type="button" @click="textoBuscar = ''; buscarAplicado = ''">
            <Icon name="close" :size="16" class="text-(--color-apagado)" />
          </button>
        </div>
        <div class="flex gap-2 overflow-x-auto pb-1">
          <SelectorSheet etiqueta="Sede" :opciones="opcionesSede" :model-value="sedeCodigo" @update:model-value="alElegirSede" />
          <SelectorSheet etiqueta="Estándar" :opciones="opcionesEstandar" v-model="estandar" />
          <SelectorSheet etiqueta="Estado" :opciones="opcionesEstado" v-model="estado" />
        </div>
      </div>
    </template>
  </PageHeader>

  <Notice
    v-if="corteQuery.data.value && !soloEditable"
    icono="lock"
    color="var(--color-alerta)"
    :mensaje="`El corte ${periodo} está cerrado: se puede consultar, pero no editar desde aquí.`"
    class="m-4 sm:mx-6"
  />

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
    :detalle="activosCount > 0 ? `Pruebe quitando alguno de los ${activosCount} filtros activos.` : 'Este corte todavía no tiene hallazgos.'"
  >
    <template v-if="activosCount > 0" #accion>
      <button type="button" class="text-[13.5px] font-medium text-(--color-sello)" @click="quitarFiltros">
        Quitar filtros
      </button>
    </template>
    <template v-else-if="soloEditable" #accion>
      <AppButton icono="plus" @click="abrirCreacion">Agregar hallazgo</AppButton>
    </template>
  </EmptyState>

  <template v-else-if="hallazgosQuery.data.value">
    <div class="bg-(--color-superficie-2) px-4 py-2.5 text-[12px] font-semibold text-(--color-tinta-2) sm:px-6">
      {{ hallazgosQuery.data.value.total }} {{ hallazgosQuery.data.value.total === 1 ? 'hallazgo' : 'hallazgos' }}
    </div>

    <button
      v-for="h in hallazgosQuery.data.value.datos"
      :key="h.hallazgoId"
      type="button"
      class="flex w-full items-start gap-3 border-b border-(--color-regla) bg-(--color-superficie) px-4 py-3.5 text-left"
      :class="soloEditable ? 'hover:bg-(--color-hundido)/40' : 'cursor-default'"
      @click="abrirEdicion(h)"
    >
      <span class="mt-0.5 h-11 w-[3px] shrink-0" :style="{ backgroundColor: EstadoHallazgo.desde(h.estado).color }" />
      <div class="min-w-0 flex-1">
        <div class="flex items-start justify-between gap-2">
          <p class="truncate text-[11.5px] font-semibold tracking-wide text-(--color-apagado)">
            {{ h.sede.nombre }} · {{ h.estandar.nombre }}
          </p>
          <StatusBadge
            :texto="EstadoHallazgo.desde(h.estado).etiqueta"
            :color="EstadoHallazgo.desde(h.estado).color"
            :fondo="EstadoHallazgo.desde(h.estado).fondo"
          />
        </div>
        <p class="mt-1 line-clamp-2 text-[13.5px] leading-snug">{{ h.descripcion }}</p>
        <p v-if="!h.presenteEnCorte" class="mt-1 text-[11.5px] italic text-(--color-alerta)">
          No se reportó en este corte
        </p>
      </div>
      <Icon v-if="soloEditable" name="edit" :size="16" class="mt-1 shrink-0 text-(--color-apagado)" />
    </button>
  </template>

  <Modal v-model="formulario.abierto" titulo="Editar hallazgo del corte">
    <div v-if="formulario.hallazgo" class="flex flex-col gap-4">
      <p class="text-[13px] leading-relaxed text-(--color-tinta-2)">{{ formulario.hallazgo.descripcion }}</p>

      <div>
        <p class="mb-2 text-[10px] font-bold tracking-wide text-(--color-apagado) uppercase">Estado</p>
        <div class="flex flex-wrap gap-2">
          <Chip
            v-for="opcion in opcionesEstadoFormulario"
            :key="opcion.valor"
            :selected="formulario.estado === opcion.valor"
            :color="opcion.color"
            @click="formulario.estado = opcion.valor"
          >
            {{ opcion.etiqueta }}
          </Chip>
        </div>
      </div>

      <AppTextarea v-model="formulario.accion" label="Acción propuesta" :rows="2" />
      <AppInput v-model="formulario.responsable" label="Responsable" />
      <AppTextarea
        v-model="formulario.evidencia"
        :label="esCierre ? 'Evidencia del cumplimiento (opcional)' : 'Evidencia'"
        :rows="3"
      />

      <Notice v-if="formulario.error" icono="error-circle" color="var(--color-critico)" :mensaje="formulario.error" />
    </div>

    <template #footer>
      <AppButton block :cargando="formulario.guardando" @click="guardar">Guardar</AppButton>
    </template>
  </Modal>

  <Modal v-model="nuevo.abierto" titulo="Agregar hallazgo" max-width="max-w-lg">
    <div class="flex flex-col gap-4">
      <p class="text-[12.5px] leading-relaxed text-(--color-apagado)">
        Se registra directo como hallazgo vigente de la sede y entra reportado en el corte de
        {{ periodo }}, sin necesidad de subir ningún archivo.
      </p>

      <div class="flex gap-2">
        <SelectorSheet
          etiqueta="Sede"
          :opciones="opcionesSede"
          :model-value="nuevo.sedeCodigo"
          @update:model-value="alElegirSedeNuevo"
        />
        <SelectorSheet etiqueta="Estándar" :opciones="opcionesEstandar" v-model="nuevo.estandar" />
      </div>

      <AppTextarea
        v-model="nuevo.descripcion"
        label="Descripción del hallazgo"
        placeholder="Describa el incumplimiento tal como se verificó…"
        :rows="3"
      />

      <div>
        <p class="mb-2 text-[10px] font-bold tracking-wide text-(--color-apagado) uppercase">Estado</p>
        <div class="flex flex-wrap gap-2">
          <Chip
            v-for="opcion in opcionesEstadoFormulario"
            :key="opcion.valor"
            :selected="nuevo.estado === opcion.valor"
            :color="opcion.color"
            @click="nuevo.estado = opcion.valor"
          >
            {{ opcion.etiqueta }}
          </Chip>
        </div>
      </div>

      <AppTextarea v-model="nuevo.accion" label="Acción propuesta" :rows="2" />
      <AppInput v-model="nuevo.responsable" label="Responsable" />
      <AppTextarea
        v-model="nuevo.evidencia"
        :label="esCierreNuevo ? 'Evidencia del cumplimiento (opcional)' : 'Evidencia'"
        :rows="3"
      />

      <Notice v-if="nuevo.error" icono="error-circle" color="var(--color-critico)" :mensaje="nuevo.error" />
    </div>

    <template #footer>
      <AppButton icono="plus" block :cargando="nuevo.guardando" @click="guardarNuevo">Agregar hallazgo</AppButton>
    </template>
  </Modal>
</template>
