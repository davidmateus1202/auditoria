<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'

import { confirmar, preguntar } from '@/composables/useDialogs'
import { ApiError } from '@/core/http/apiClient'
import { Formato } from '@/core/format'
import { guardarArchivo } from '@/core/downloads'
import { corteAbierto, corteAvanceDelMes } from '@/domain/models'
import {
  useAbrirCorteMutation,
  useCerrarCorteMutation,
  useCorteQuery,
  useCortesQuery,
  useDescargarMatrizMutation,
  useSubirMatrizMutation,
} from '@/queries/useCortes'
import { useUiStore } from '@/stores/ui.store'
import AppButton from '@/components/ui/AppButton.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import Icon from '@/components/ui/Icon.vue'
import Modal from '@/components/ui/Modal.vue'
import Notice from '@/components/ui/Notice.vue'
import Panel from '@/components/ui/Panel.vue'
import SectionHeading from '@/components/ui/SectionHeading.vue'
import StatCard from '@/components/ui/StatCard.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import PageHeader from '@/components/layout/PageHeader.vue'

/** El mes de seguimiento: qué sedes ya reportaron y qué falta. Mirror de
 * PantallaCorte (pantallas/corte.dart). */
const ui = useUiStore()
const router = useRouter()

const cortesQuery = useCortesQuery()
const abierto = computed(() => cortesQuery.data.value?.find((c) => corteAbierto(c)) ?? null)
const periodoAbierto = computed(() => abierto.value?.periodo ?? '')

const detalleQuery = useCorteQuery(periodoAbierto, computed(() => abierto.value !== null))

const abrirMutation = useAbrirCorteMutation()
const cerrarMutation = useCerrarCorteMutation()
const descargarMutation = useDescargarMatrizMutation()
const subirMutation = useSubirMatrizMutation()

const trabajando = computed(
  () => abrirMutation.isPending.value || cerrarMutation.isPending.value || descargarMutation.isPending.value || subirMutation.isPending.value,
)

function mensajeDe(e: unknown): string {
  return e instanceof ApiError ? e.mensaje : `${e}`
}

async function abrirMes() {
  const ahora = new Date()
  const sugerido = `${ahora.getFullYear()}-${String(ahora.getMonth() + 1).padStart(2, '0')}`

  const periodo = await preguntar({
    titulo: 'Abrir corte del mes',
    label: 'Periodo',
    placeholder: 'AAAA-MM',
    valorInicial: sugerido,
    textoConfirmar: 'Abrir',
  })
  if (!periodo) return

  if (!/^\d{4}-(0[1-9]|1[0-2])$/.test(periodo)) {
    ui.mostrar('El periodo debe tener el formato AAAA-MM.', { error: true })
    return
  }

  try {
    await abrirMutation.mutateAsync(periodo)
  } catch (e) {
    ui.mostrar(mensajeDe(e), { error: true })
  }
}

async function descargarMatriz() {
  if (!abierto.value) return
  try {
    const blob = await descargarMutation.mutateAsync(abierto.value.periodo)
    guardarArchivo(`seguimiento-hallazgos-${abierto.value.periodo}.xlsx`, blob)
    ui.mostrar(`Matriz de ${Formato.periodo(abierto.value.periodo)} descargada (${Math.round(blob.size / 1024)} KB).`)
  } catch (e) {
    ui.mostrar(`No se pudo descargar: ${mensajeDe(e)}`, { error: true })
  }
}

const inputArchivo = ref<HTMLInputElement | null>(null)
const resumenCarga = ref<Record<string, unknown> | null>(null)

function abrirSelectorMatriz() {
  inputArchivo.value?.click()
}

async function alElegirMatriz(evento: Event) {
  const archivo = (evento.target as HTMLInputElement).files?.[0]
  if (!archivo || !abierto.value) return
  if (inputArchivo.value) inputArchivo.value.value = ''

  try {
    const resumen = await subirMutation.mutateAsync({ periodo: abierto.value.periodo, archivo })
    resumenCarga.value = resumen
  } catch (e) {
    ui.mostrar(mensajeDe(e), { error: true })
  }
}

async function cerrarMes() {
  if (!abierto.value) return

  const ok = await confirmar({
    titulo: `¿Cerrar ${Formato.periodo(abierto.value.periodo)}?`,
    mensaje:
      'Al cerrar el mes se congela la foto de cada hallazgo. El consolidado de este periodo quedará reproducible para siempre, y reabrirlo después exigirá un motivo registrado.',
    textoConfirmar: 'Cerrar el mes',
  })
  if (!ok) return

  try {
    await cerrarMutation.mutateAsync(abierto.value.periodo)
    ui.mostrar(`Corte de ${Formato.periodo(abierto.value.periodo)} cerrado.`)
  } catch (e) {
    ui.mostrar(mensajeDe(e), { error: true })
  }
}

function n(obj: Record<string, unknown> | null, clave: string): number {
  return Number(obj?.[clave] ?? 0)
}
</script>

<template>
  <PageHeader titulo="Corte del mes" />

  <div v-if="cortesQuery.isPending.value" class="flex justify-center py-20 text-(--color-apagado)">Cargando…</div>
  <ErrorState v-else-if="cortesQuery.isError.value" :error="cortesQuery.error.value" :reintentar="() => cortesQuery.refetch()" />

  <EmptyState
    v-else-if="cortesQuery.data.value && cortesQuery.data.value.length === 0"
    icono="calendar"
    titulo="Todavía no hay ningún corte"
    detalle="Abra el mes para empezar a registrar el seguimiento; luego podrá importar la matriz ya diligenciada."
  >
    <template #accion>
      <AppButton icono="calendar" @click="abrirMes">Abrir el mes</AppButton>
    </template>
  </EmptyState>

  <div v-else-if="cortesQuery.data.value" class="mx-auto max-w-3xl space-y-5 p-4 pb-10 sm:p-6">
    <Notice v-if="!abierto" icono="lock" mensaje="No hay ningún mes abierto. Todos los cortes registrados están cerrados y sus cifras congeladas.">
      <template #accion>
        <button type="button" class="text-[13px] font-medium text-(--color-sello)" @click="abrirMes">Abrir mes</button>
      </template>
    </Notice>

    <template v-else>
      <Panel>
        <div v-if="detalleQuery.isPending.value" class="flex justify-center py-8 text-(--color-apagado)">Cargando…</div>
        <p v-else-if="detalleQuery.isError.value">{{ detalleQuery.error.value }}</p>
        <div v-else-if="detalleQuery.data.value">
          <div class="flex items-center justify-between">
            <p class="text-[18px] font-bold">{{ Formato.periodo(detalleQuery.data.value.periodo) }}</p>
            <StatusBadge texto="Abierto" color="var(--color-alerta)" fondo="var(--color-alerta-fondo)" />
          </div>

          <div class="mt-4 grid grid-cols-3 gap-3">
            <StatCard :valor="Formato.numero(detalleQuery.data.value.reportados)" rotulo="Reportados" color="var(--color-bien)" />
            <StatCard
              :valor="Formato.numero(detalleQuery.data.value.pendientes)"
              rotulo="Sin reportar"
              :color="detalleQuery.data.value.pendientes > 0 ? 'var(--color-alerta)' : 'var(--color-apagado)'"
            />
            <StatCard :valor="Formato.numero(detalleQuery.data.value.hallazgos)" rotulo="Vigentes&#10;en el mes" />
          </div>

          <div class="mt-4 h-2 overflow-hidden rounded-[2px] bg-(--color-hundido)">
            <div
              class="h-full rounded-[1px]"
              style="background-color: var(--color-bien)"
              :style="{ width: `${corteAvanceDelMes(detalleQuery.data.value) * 100}%` }"
            />
          </div>

          <template v-if="Object.keys(detalleQuery.data.value.porSede).length > 0">
            <p class="mt-4.5 text-[10px] font-bold tracking-wide text-(--color-apagado) uppercase">Por sede</p>
            <div class="mt-2 space-y-1.5">
              <div
                v-for="[sede, valores] in Object.entries(detalleQuery.data.value.porSede)"
                :key="sede"
                class="flex items-center gap-2.5"
              >
                <span class="w-24 shrink-0 truncate text-[12px]">{{ sede }}</span>
                <span class="h-1.5 flex-1 overflow-hidden rounded-full bg-(--color-hundido)">
                  <span
                    class="block h-full rounded-full"
                    style="background-color: var(--color-sello)"
                    :style="{ width: `${(valores.total ?? 0) === 0 ? 0 : ((valores.reportados ?? 0) / valores.total) * 100}%` }"
                  />
                </span>
                <span class="tabular w-12 shrink-0 text-right text-[11.5px] text-(--color-apagado)">
                  {{ valores.reportados }}/{{ valores.total }}
                </span>
              </div>
            </div>
          </template>
        </div>
      </Panel>

      <div>
        <SectionHeading texto="La matriz va y vuelve" />
        <div class="flex gap-2.5">
          <AppButton variante="outlined" icono="download" class="flex-1" :disabled="trabajando" @click="descargarMatriz">
            Descargar
          </AppButton>
          <AppButton icono="upload" class="flex-1" :disabled="trabajando" @click="abrirSelectorMatriz">Subir</AppButton>
          <input ref="inputArchivo" type="file" accept=".xlsx,.xls" class="hidden" @change="alElegirMatriz" />
        </div>
        <p class="mt-2.5 text-[12px] leading-relaxed text-(--color-apagado)">
          Se descarga en el formato de siempre, se edita en Excel y se vuelve a subir. Cada fila viaja con su
          identificador, así que el regreso es exacto aunque cambien de orden.
        </p>
      </div>

      <div>
        <SectionHeading texto="O editarlos aquí mismo" />
        <AppButton
          variante="outlined"
          icono="edit"
          block
          @click="router.push({ name: 'corte-hallazgos', params: { periodo: abierto.periodo } })"
        >
          Editar hallazgos del mes
        </AppButton>
        <p class="mt-2.5 text-[12px] leading-relaxed text-(--color-apagado)">
          Cambia el estado, la acción propuesta, el responsable o la evidencia de cada hallazgo sin salir del
          navegador. Es la misma foto que alimenta el consolidado: se puede usar en vez del Excel, o para corregir
          algo puntual después de subir la matriz.
        </p>
      </div>

      <AppButton variante="outlined" tono="tinta" icono="lock" :disabled="trabajando" @click="cerrarMes">
        Cerrar el mes
      </AppButton>
    </template>

    <div>
      <SectionHeading texto="Meses registrados" />
      <Panel sin-padding>
        <button
          v-for="(corte, i) in cortesQuery.data.value"
          :key="corte.periodo"
          type="button"
          class="flex w-full items-center gap-3 px-3.5 py-3 text-left hover:bg-(--color-hundido)/40"
          :class="i > 0 ? 'border-t border-(--color-regla)' : ''"
          @click="router.push({ name: 'corte-hallazgos', params: { periodo: corte.periodo } })"
        >
          <Icon
            :name="corteAbierto(corte) ? 'calendar-edit' : 'calendar-check'"
            :size="20"
            :style="{ color: corteAbierto(corte) ? 'var(--color-alerta)' : 'var(--color-bien)' }"
          />
          <div class="min-w-0 flex-1">
            <p class="text-[14px]">{{ Formato.periodo(corte.periodo) }}</p>
            <p class="text-[12px] text-(--color-apagado)">
              {{ corteAbierto(corte) ? 'Abierto · admite cambios' : 'Cerrado · cifras congeladas' }}
            </p>
          </div>
          <Icon name="chevron-right" :size="18" class="shrink-0 text-(--color-regla)" />
        </button>
      </Panel>
    </div>
  </div>

  <Modal :model-value="resumenCarga !== null" titulo="Matriz procesada" @update:model-value="resumenCarga = null">
    <template v-if="resumenCarga">
      <div class="space-y-1.5">
        <div class="flex justify-between text-[13.5px]">
          <span>Filas leídas</span>
          <span class="tabular font-bold">{{ Formato.numero(n(resumenCarga, 'filas')) }}</span>
        </div>
        <div class="flex justify-between text-[13.5px]">
          <span>Cambios aplicados</span>
          <span class="tabular font-bold" style="color: var(--color-bien)">{{ Formato.numero(n(resumenCarga, 'aplicados')) }}</span>
        </div>
        <div class="flex justify-between text-[13.5px]">
          <span>Sin cambio</span>
          <span class="tabular font-bold">{{ Formato.numero(n(resumenCarga, 'sin_cambio')) }}</span>
        </div>
        <div v-if="n(resumenCarga, 'nuevos') > 0" class="flex justify-between text-[13.5px]">
          <span>Hallazgos nuevos</span>
          <span class="tabular font-bold">{{ Formato.numero(n(resumenCarga, 'nuevos')) }}</span>
        </div>
        <div v-if="n(resumenCarga, 'conflictos') > 0" class="flex justify-between text-[13.5px]">
          <span>Conflictos</span>
          <span class="tabular font-bold" style="color: var(--color-critico)">{{ Formato.numero(n(resumenCarga, 'conflictos')) }}</span>
        </div>
        <div v-if="n(resumenCarga, 'ausentes') > 0" class="flex justify-between text-[13.5px]">
          <span>No volvieron en el archivo</span>
          <span class="tabular font-bold" style="color: var(--color-alerta)">{{ Formato.numero(n(resumenCarga, 'ausentes')) }}</span>
        </div>
      </div>

      <Notice
        v-if="n(resumenCarga, 'conflictos') > 0"
        class="mt-3.5"
        icono="merge"
        color="var(--color-critico)"
        mensaje="Algunos hallazgos se tocaron en la app y en el Excel a la vez. Se conservó lo que había en la app hasta que alguien decida cuál versión vale."
      />
      <Notice
        v-if="n(resumenCarga, 'ausentes') > 0"
        class="mt-2.5"
        icono="info"
        color="var(--color-alerta)"
        mensaje="Los hallazgos que no venían en el archivo conservan su estado. No se cierran por omisión: lo más probable es que la fila se quedara sin digitar."
      />
      <Notice
        v-for="(advertencia, i) in (resumenCarga.advertencias as string[] | undefined) ?? []"
        :key="i"
        class="mt-2.5"
        color="var(--color-alerta)"
        :mensaje="advertencia"
      />
    </template>

    <template #footer>
      <AppButton block @click="resumenCarga = null">Entendido</AppButton>
    </template>
  </Modal>
</template>
