<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'

import type { HojaPrevia, MensajeDesdeWorker } from '@/workers/spreadsheetPreview.worker'
import Icon from './Icon.vue'
import Modal from './Modal.vue'

/**
 * Previsualización de un Excel en el navegador: se parsea del lado del
 * cliente con ExcelJS (nunca sale del navegador) y se dibuja como tabla. No
 * existe un visor nativo de .xlsx en el navegador, así que esta es la forma
 * de «abrirlo» sin descargarlo ni depender de un servicio externo.
 *
 * El parseo corre en un Web Worker, no aquí: en un archivo real de varios MB
 * ExcelJS tarda diez segundos o más, y hecho en el hilo principal eso
 * congela la pestaña entera —ni el spinner anima— durante ese rato. El
 * `import type` de abajo no arrastra el worker (ni ExcelJS) a este chunk:
 * solo toma los tipos, que se borran al compilar.
 */
const props = defineProps<{
  modelValue: boolean
  nombreArchivo: string
  buffer: ArrayBuffer | null
  cargando?: boolean
  error?: string | null
}>()

defineEmits<{ 'update:modelValue': [boolean] }>()

const hojas = ref<HojaPrevia[]>([])
const hojaActiva = ref(0)
const parseando = ref(false)
const errorParseo = ref<string | null>(null)

// Debe coincidir con el mismo límite en workers/spreadsheetPreview.worker.ts.
const LIMITE_FILAS = 300

let worker: Worker | null = null

function detenerWorker() {
  worker?.terminate()
  worker = null
}

function parsear(buffer: ArrayBuffer) {
  detenerWorker()
  parseando.value = true
  errorParseo.value = null
  hojas.value = []

  worker = new Worker(new URL('../../workers/spreadsheetPreview.worker.ts', import.meta.url), {
    type: 'module',
  })

  worker.onmessage = (evento: MessageEvent<MensajeDesdeWorker>) => {
    if (evento.data.ok) {
      hojas.value = evento.data.hojas
      hojaActiva.value = 0
    } else {
      errorParseo.value = `No se pudo leer el archivo para previsualizarlo: ${evento.data.error}`
    }
    parseando.value = false
    detenerWorker()
  }

  worker.onerror = (evento) => {
    errorParseo.value = `No se pudo leer el archivo para previsualizarlo: ${evento.message}`
    parseando.value = false
    detenerWorker()
  }

  worker.postMessage({ buffer })
}

watch(
  () => props.buffer,
  (nuevo) => {
    if (nuevo) parsear(nuevo)
  },
  { immediate: true },
)

onBeforeUnmount(detenerWorker)

const hoja = computed(() => hojas.value[hojaActiva.value] ?? null)
</script>

<template>
  <Modal :model-value="modelValue" :titulo="nombreArchivo" max-width="max-w-4xl" @update:model-value="$emit('update:modelValue', $event)">
    <div v-if="cargando || parseando" class="flex flex-col items-center gap-2 py-14 text-(--color-apagado)">
      <svg class="h-6 w-6 animate-spin" viewBox="0 0 24 24" fill="none">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" />
        <path class="opacity-80" fill="currentColor" d="M12 2a10 10 0 0 1 10 10h-3a7 7 0 0 0-7-7z" />
      </svg>
      <span class="text-[13px]">{{ cargando ? 'Descargando el archivo…' : 'Leyendo el contenido…' }}</span>
    </div>

    <div v-else-if="error || errorParseo" class="flex flex-col items-center gap-2 py-14 text-center">
      <Icon name="error-circle" :size="28" style="color: var(--color-critico)" />
      <p class="max-w-sm text-[13px] text-(--color-tinta-2)">{{ error ?? errorParseo }}</p>
    </div>

    <div v-else-if="hoja" class="flex h-[65vh] flex-col">
      <div v-if="hojas.length > 1" class="mb-2.5 flex gap-1 overflow-x-auto border-b border-(--color-regla)">
        <button
          v-for="(h, i) in hojas"
          :key="h.nombre"
          type="button"
          class="shrink-0 border-b-2 px-3 py-2 text-[12.5px] font-medium whitespace-nowrap"
          :class="
            i === hojaActiva
              ? 'border-(--color-sello) text-(--color-sello)'
              : 'border-transparent text-(--color-apagado)'
          "
          @click="hojaActiva = i"
        >
          {{ h.nombre }}
        </button>
      </div>

      <p v-if="hoja.truncada" class="mb-2 text-[11.5px] text-(--color-apagado)">
        Mostrando las primeras {{ LIMITE_FILAS }} filas de esta hoja.
      </p>

      <div class="min-h-0 flex-1 overflow-auto rounded border border-(--color-regla)">
        <table class="w-full border-collapse text-left text-[12px]">
          <tbody>
            <tr v-for="(fila, i) in hoja.filas" :key="i" :class="i > 0 ? 'border-t border-(--color-regla)' : ''">
              <td
                v-for="(celda, j) in fila"
                :key="j"
                class="px-2.5 py-1.5 whitespace-nowrap"
                :class="i === 0 ? 'bg-(--color-superficie-2) font-semibold' : ''"
              >
                {{ celda }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div v-else class="py-14 text-center text-[13px] text-(--color-apagado)">Este archivo no tiene hojas con datos.</div>
  </Modal>
</template>
