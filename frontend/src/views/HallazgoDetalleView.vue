<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import { ApiError } from '@/core/http/apiClient'
import { Formato } from '@/core/format'
import { EstadoHallazgo, type EstadoHallazgoValor } from '@/domain/enums'
import { useCambiarEstadoMutation, useHallazgoQuery, useLineaTiempoQuery } from '@/queries/useHallazgos'
import AppButton from '@/components/ui/AppButton.vue'
import AppTextarea from '@/components/ui/AppTextarea.vue'
import Chip from '@/components/ui/Chip.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import Icon from '@/components/ui/Icon.vue'
import Modal from '@/components/ui/Modal.vue'
import Notice from '@/components/ui/Notice.vue'
import Panel from '@/components/ui/Panel.vue'
import SectionHeading from '@/components/ui/SectionHeading.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import PageHeader from '@/components/layout/PageHeader.vue'

/** Mirror de PantallaDetalleHallazgo (pantallas/detalle_hallazgo.dart). */
const route = useRoute()
const router = useRouter()
const hallazgoId = computed(() => Number(route.params.id))

const hallazgoQuery = useHallazgoQuery(hallazgoId)
const lineaTiempoQuery = useLineaTiempoQuery(hallazgoId)

const porCorte = computed(() => {
  const datos = lineaTiempoQuery.data.value
  const lista = datos && Array.isArray(datos.por_corte) ? datos.por_corte : []
  return lista as Record<string, unknown>[]
})

// ── Formulario de seguimiento ─────────────────────────────────────────────
const formularioAbierto = ref(false)
const estadoElegido = ref<EstadoHallazgoValor>('abierto')
const accion = ref('')
const evidencia = ref('')
const guardando = ref(false)
const errorFormulario = ref<string | null>(null)

const cambiarEstadoMutation = useCambiarEstadoMutation()

const opcionesEstado = EstadoHallazgo.valores.filter((e) => e.valor !== 'sin_dato')
const esCierre = computed(() => estadoElegido.value === 'cerrado')

function abrirFormulario() {
  const h = hallazgoQuery.data.value
  if (!h) return
  estadoElegido.value = h.estado.valor === 'sin_dato' ? 'abierto' : h.estado.valor
  accion.value = h.accionPropuesta ?? ''
  evidencia.value = h.evidencia ?? ''
  errorFormulario.value = null
  formularioAbierto.value = true
}

async function guardar() {

  guardando.value = true
  errorFormulario.value = null

  try {
    await cambiarEstadoMutation.mutateAsync({
      hallazgoId: hallazgoId.value,
      cambio: {
        estado: estadoElegido.value,
        evidencia: evidencia.value.trim(),
        accionPropuesta: accion.value.trim(),
      },
    })
    formularioAbierto.value = false
  } catch (e) {
    errorFormulario.value = e instanceof ApiError ? e.mensaje : `No se pudo guardar: ${e}`
  } finally {
    guardando.value = false
  }
}
</script>

<template>
  <PageHeader titulo="Hallazgo" con-volver @volver="router.back()">
    <template #volver-icono><Icon name="arrow-left" :size="19" /></template>
  </PageHeader>

  <div v-if="hallazgoQuery.isPending.value" class="flex justify-center py-20 text-(--color-apagado)">Cargando…</div>
  <ErrorState
    v-else-if="hallazgoQuery.isError.value"
    :error="hallazgoQuery.error.value"
    :reintentar="() => hallazgoQuery.refetch()"
  />

  <template v-else-if="hallazgoQuery.data.value">
    <div class="mx-auto max-w-2xl space-y-5 p-4 sm:p-6">
      <div class="flex items-start gap-2.5">
        <StatusBadge
          :texto="hallazgoQuery.data.value.estado.etiqueta"
          :color="hallazgoQuery.data.value.estado.color"
          :fondo="hallazgoQuery.data.value.estado.fondo"
        />
        <span class="pt-0.5 text-[12px] font-semibold text-(--color-apagado)">
          {{ hallazgoQuery.data.value.nombreEstandar }}
        </span>
      </div>

      <p class="text-[16px] leading-relaxed">{{ hallazgoQuery.data.value.descripcion }}</p>

      <Panel sin-padding>
        <div class="grid grid-cols-1 divide-y divide-(--color-regla)">
          <div class="px-3.5 py-3">
            <p class="text-[10px] font-bold tracking-wide text-(--color-apagado) uppercase">Sede</p>
            <p class="mt-1 text-[14px]">{{ hallazgoQuery.data.value.sedeNombre }}</p>
          </div>
          <div v-if="hallazgoQuery.data.value.servicio" class="px-3.5 py-3">
            <p class="text-[10px] font-bold tracking-wide text-(--color-apagado) uppercase">Servicio</p>
            <p class="mt-1 text-[14px]">{{ hallazgoQuery.data.value.servicio }}</p>
          </div>
          <div class="px-3.5 py-3">
            <p class="text-[10px] font-bold tracking-wide text-(--color-apagado) uppercase">Acción propuesta</p>
            <p
              class="mt-1 text-[14px]"
              :class="!hallazgoQuery.data.value.accionPropuesta ? 'text-(--color-apagado) italic' : ''"
            >
              {{ hallazgoQuery.data.value.accionPropuesta ?? 'Sin registrar' }}
            </p>
          </div>
          <div class="px-3.5 py-3">
            <p class="text-[10px] font-bold tracking-wide text-(--color-apagado) uppercase">Responsable</p>
            <p
              class="mt-1 text-[14px]"
              :class="!hallazgoQuery.data.value.responsable ? 'text-(--color-apagado) italic' : ''"
            >
              {{ hallazgoQuery.data.value.responsable ?? 'Sin asignar' }}
            </p>
          </div>
          <div class="px-3.5 py-3">
            <p class="text-[10px] font-bold tracking-wide text-(--color-apagado) uppercase">Evidencia</p>
            <p
              class="mt-1 text-[14px]"
              :class="!hallazgoQuery.data.value.evidencia ? 'text-(--color-apagado) italic' : ''"
            >
              {{ hallazgoQuery.data.value.evidencia ?? 'Sin evidencia registrada' }}
            </p>
          </div>
        </div>
      </Panel>

      <div>
        <SectionHeading texto="Paso por cada corte" />

        <div v-if="lineaTiempoQuery.isPending.value" class="flex justify-center py-6 text-(--color-apagado)">
          Cargando…
        </div>
        <Notice
          v-else-if="lineaTiempoQuery.isError.value"
          icono="error-circle"
          color="var(--color-critico)"
          :mensaje="`No se pudo cargar la historia: ${lineaTiempoQuery.error.value instanceof ApiError ? lineaTiempoQuery.error.value.mensaje : lineaTiempoQuery.error.value}`"
        />
        <Notice
          v-else-if="porCorte.length === 0"
          icono="timeline"
          mensaje="Este hallazgo todavía no ha pasado por ningún corte cerrado."
        />
        <Panel v-else sin-padding>
          <div
            v-for="(paso, i) in porCorte"
            :key="i"
            class="flex items-center gap-3 px-3.5 py-3"
            :class="i > 0 ? 'border-t border-(--color-regla)' : ''"
          >
            <span
              class="h-2 w-2 shrink-0 rounded-full"
              :style="{ backgroundColor: EstadoHallazgo.desde(paso.estado as string).color }"
            />
            <div class="min-w-0 flex-1">
              <p class="text-[13.5px] font-semibold">
                {{ Formato.periodo(((paso.corte as Record<string, unknown> | undefined)?.periodo as string) ?? '') }}
              </p>
              <p v-if="paso.presente_en_corte !== true" class="text-[11.5px] italic text-(--color-alerta)">
                No se reportó en este corte
              </p>
              <p v-else-if="(paso.meses_abierto as number) > 1" class="text-[11.5px] text-(--color-apagado)">
                Lleva {{ paso.meses_abierto }} meses abierto
              </p>
            </div>
            <StatusBadge
              :texto="EstadoHallazgo.desde(paso.estado as string).etiqueta"
              :color="EstadoHallazgo.desde(paso.estado as string).color"
              :fondo="EstadoHallazgo.desde(paso.estado as string).fondo"
            />
          </div>
        </Panel>
      </div>
    </div>

    <div class="sticky bottom-0 z-10 border-t border-(--color-regla) bg-(--color-superficie) p-4">
      <div class="mx-auto max-w-2xl">
        <AppButton icono="edit" block @click="abrirFormulario">Registrar seguimiento</AppButton>
      </div>
    </div>
  </template>

  <Modal v-model="formularioAbierto" titulo="Registrar seguimiento">
    <div class="flex flex-col gap-4">
      <div>
        <p class="mb-2 text-[10px] font-bold tracking-wide text-(--color-apagado) uppercase">Estado</p>
        <div class="flex flex-wrap gap-2">
          <Chip
            v-for="estado in opcionesEstado"
            :key="estado.valor"
            :selected="estadoElegido === estado.valor"
            :color="estado.color"
            @click="estadoElegido = estado.valor"
          >
            {{ estado.etiqueta }}
          </Chip>
        </div>
      </div>

      <AppTextarea v-model="accion" label="Acción propuesta" :rows="2" />

      <AppTextarea
        v-model="evidencia"
        :label="esCierre ? 'Evidencia del cumplimiento (opcional)' : 'Evidencia'"
        :rows="3"
      />

      <Notice v-if="errorFormulario" icono="error-circle" color="var(--color-critico)" :mensaje="errorFormulario" />
    </div>

    <template #footer>
      <AppButton block :cargando="guardando" @click="guardar">Guardar</AppButton>
    </template>
  </Modal>
</template>
