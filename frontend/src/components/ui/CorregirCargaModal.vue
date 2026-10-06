<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, reactive, ref, watch } from 'vue'

import type { CorreccionCelda } from '@/data/auditorias.repository'
import type { HojaPrevia, MensajeAlWorker, MensajeDesdeWorker } from '@/workers/spreadsheetPreview.worker'
import AppButton from './AppButton.vue'
import Icon from './Icon.vue'
import Modal from './Modal.vue'

/**
 * Vista previa de un archivo cuya carga falló, para corregirlo sin salir del
 * sistema: señala las celdas que no coinciden con el catálogo, propone el
 * nombre más parecido y deja cambiar el valor de cualquier celda. Las
 * correcciones viajan con el archivo al volver a cargarlo y el servidor las
 * aplica al leerlo; el Excel original no se modifica.
 *
 * El archivo se lee en el navegador con el mismo worker de ExcelJS de la
 * previsualización normal, así que nunca sale del equipo para mostrarse.
 */
interface Problema {
  tipo: string
  hoja: string
  celda: string
  fila: number
  encontrado: string
  sugerencia: string | null
}

const props = defineProps<{
  modelValue: boolean
  archivo: File | null
  fallo: Record<string, unknown> | null
  /** Nombres del catálogo de estándares, para elegir uno en vez de escribirlo. */
  estandares: string[]
  cargando?: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [boolean]
  reintentar: [CorreccionCelda[]]
}>()

// ── Problemas que informó el servidor ──────────────────────────────────────
const problemas = computed<Problema[]>(() => (props.fallo?.problemas as Problema[] | undefined) ?? [])

/** Fila señalada por un error de estructura sin celda concreta (p. ej. el encabezado). */
const filaSenalada = computed(() => {
  if (!props.fallo || problemas.value.length > 0) return null
  const hoja = props.fallo.hoja as string | undefined
  return hoja ? { hoja, fila: (props.fallo.fila as number | undefined) ?? null } : null
})

// ── Correcciones ───────────────────────────────────────────────────────────
const correcciones = reactive<Record<string, CorreccionCelda>>({})

// Antes del watch inmediato de más abajo, que las reinicia al abrir.
const seleccion = ref<{ hoja: string; celda: string; original: string } | null>(null)
const valorEditado = ref('')

function clave(hoja: string, celda: string): string {
  return `${normalizar(hoja)}!${celda}`
}

function normalizar(hoja: string): string {
  return hoja.trim().toLocaleUpperCase('es-CO')
}

function corregir(hoja: string, celda: string, valor: string) {
  correcciones[clave(hoja, celda)] = { hoja, celda, valor }
}

function deshacer(hoja: string, celda: string) {
  delete correcciones[clave(hoja, celda)]
}

function correccionDe(hoja: string, celda: string): CorreccionCelda | undefined {
  return correcciones[clave(hoja, celda)]
}

const totalCorrecciones = computed(() => Object.keys(correcciones).length)
const pendientes = computed(() => problemas.value.filter((p) => !correccionDe(p.hoja, p.celda)).length)

// ── Lectura del archivo (worker) ───────────────────────────────────────────
const hojas = ref<HojaPrevia[]>([])
const hojaActiva = ref(0)
const leyendo = ref(false)
const errorLectura = ref<string | null>(null)
let worker: Worker | null = null

function detenerWorker() {
  worker?.terminate()
  worker = null
}

async function leer(archivo: File) {
  detenerWorker()
  hojas.value = []
  errorLectura.value = null

  // ExcelJS solo entiende .xlsx; con un .xls se puede corregir igual desde
  // la lista de celdas, solo que sin la tabla al lado.
  if (!archivo.name.toLowerCase().endsWith('.xlsx')) {
    errorLectura.value =
      'La vista previa solo está disponible para archivos .xlsx. Puede corregir las celdas señaladas desde la lista.'
    return
  }

  leyendo.value = true
  const filasMinimas: Record<string, number> = {}
  for (const p of problemas.value) filasMinimas[p.hoja] = Math.max(filasMinimas[p.hoja] ?? 0, p.fila + 10)
  if (filaSenalada.value?.fila) filasMinimas[filaSenalada.value.hoja] = filaSenalada.value.fila + 10

  worker = new Worker(new URL('../../workers/spreadsheetPreview.worker.ts', import.meta.url), { type: 'module' })

  worker.onmessage = async (evento: MessageEvent<MensajeDesdeWorker>) => {
    if (evento.data.ok) {
      hojas.value = evento.data.hojas
      await nextTick()
      irAlPrimerProblema()
    } else {
      errorLectura.value = `No se pudo leer el archivo para mostrarlo: ${evento.data.error}`
    }
    leyendo.value = false
    detenerWorker()
  }

  worker.onerror = (evento) => {
    errorLectura.value = `No se pudo leer el archivo para mostrarlo: ${evento.message}`
    leyendo.value = false
    detenerWorker()
  }

  const mensaje: MensajeAlWorker = { buffer: await archivo.arrayBuffer(), filasMinimas }
  worker.postMessage(mensaje)
}

watch(
  () => [props.modelValue, props.archivo] as const,
  ([abierto, archivo]) => {
    if (!abierto || !archivo) return
    for (const k of Object.keys(correcciones)) delete correcciones[k]
    seleccion.value = null
    // Cada celda señalada arranca con su sugerencia ya puesta: en el caso
    // común basta con revisar y volver a cargar.
    for (const p of problemas.value) if (p.sugerencia) corregir(p.hoja, p.celda, p.sugerencia)
    leer(archivo)
  },
  { immediate: true },
)

onBeforeUnmount(detenerWorker)

// ── Tabla ──────────────────────────────────────────────────────────────────
const hoja = computed(() => hojas.value[hojaActiva.value] ?? null)
const totalColumnas = computed(() => Math.max(0, ...(hoja.value?.filas.map((f) => f.length) ?? [0])))

function letra(indice: number): string {
  let n = indice + 1
  let texto = ''
  while (n > 0) {
    const resto = (n - 1) % 26
    texto = String.fromCharCode(65 + resto) + texto
    n = Math.floor((n - 1) / 26)
  }
  return texto
}

function celdaDe(fila: number, columna: number): string {
  return `${letra(columna)}${fila + 1}`
}

const problemasPorCelda = computed(() => {
  const mapa = new Map<string, Problema>()
  for (const p of problemas.value) mapa.set(clave(p.hoja, p.celda), p)
  return mapa
})

function claseCelda(fila: number, columna: number): string {
  if (!hoja.value) return ''
  const celda = celdaDe(fila, columna)
  const k = clave(hoja.value.nombre, celda)
  const seleccionada =
    seleccion.value && normalizar(seleccion.value.hoja) === normalizar(hoja.value.nombre) && seleccion.value.celda === celda

  const partes: string[] = []
  if (correcciones[k]) partes.push('bg-(--color-bien-fondo) text-(--color-bien) font-semibold')
  else if (problemasPorCelda.value.has(k)) partes.push('bg-(--color-critico-fondo) text-(--color-critico) font-semibold')
  else if (
    filaSenalada.value?.fila === fila + 1 &&
    normalizar(filaSenalada.value.hoja) === normalizar(hoja.value.nombre)
  )
    partes.push('bg-(--color-alerta-fondo)')
  if (seleccionada) partes.push('outline-2 -outline-offset-2 outline-(--color-sello)')
  return partes.join(' ')
}

function textoCelda(fila: number, columna: number): string {
  if (!hoja.value) return ''
  const correccion = correccionDe(hoja.value.nombre, celdaDe(fila, columna))
  return correccion ? correccion.valor : (hoja.value.filas[fila]?.[columna] ?? '')
}

// ── Selección y edición ────────────────────────────────────────────────────
function seleccionar(fila: number, columna: number) {
  if (!hoja.value) return
  const celda = celdaDe(fila, columna)
  const original = hoja.value.filas[fila]?.[columna] ?? ''
  seleccion.value = { hoja: hoja.value.nombre, celda, original }
  valorEditado.value = correccionDe(hoja.value.nombre, celda)?.valor ?? original
}

function aplicarEdicion() {
  if (!seleccion.value) return
  const { hoja: h, celda, original } = seleccion.value
  if (valorEditado.value === original) deshacer(h, celda)
  else corregir(h, celda, valorEditado.value)
}

const problemaSeleccionado = computed(() =>
  seleccion.value ? problemasPorCelda.value.get(clave(seleccion.value.hoja, seleccion.value.celda)) : undefined,
)

/** Lleva la tabla a una celda: cambia de hoja, la selecciona y la centra. */
async function irA(hojaNombre: string, celda: string) {
  const indice = hojas.value.findIndex((h) => normalizar(h.nombre) === normalizar(hojaNombre))
  const coincidencia = /^([A-Z]+)(\d+)$/.exec(celda)
  if (indice < 0 || !coincidencia) {
    // Sin tabla (p. ej. un .xls) igual se puede editar desde el panel.
    seleccion.value = { hoja: hojaNombre, celda, original: problemasPorCelda.value.get(clave(hojaNombre, celda))?.encontrado ?? '' }
    valorEditado.value = correccionDe(hojaNombre, celda)?.valor ?? seleccion.value.original
    return
  }

  hojaActiva.value = indice
  const columna = coincidencia[1].split('').reduce((n, c) => n * 26 + c.charCodeAt(0) - 64, 0) - 1
  const fila = Number(coincidencia[2]) - 1
  seleccionar(fila, columna)

  await nextTick()
  centrar(document.getElementById(`pestana-${indice}`))
  centrar(document.getElementById(`celda-${indice}-${fila}-${columna}`))
}

/**
 * Centra un elemento dentro de su contenedor con scroll, sin mover nada más.
 * scrollIntoView desplazaba también el cuerpo del modal y escondía el editor.
 */
function centrar(elemento: HTMLElement | null) {
  let contenedor = elemento?.parentElement ?? null
  while (contenedor && contenedor.scrollHeight <= contenedor.clientHeight && contenedor.scrollWidth <= contenedor.clientWidth) {
    contenedor = contenedor.parentElement
  }
  if (!elemento || !contenedor) return

  const caja = elemento.getBoundingClientRect()
  const marco = contenedor.getBoundingClientRect()
  contenedor.scrollTop += caja.top - marco.top - (marco.height - caja.height) / 2
  contenedor.scrollLeft += caja.left - marco.left - (marco.width - caja.width) / 2
}

function irAlPrimerProblema() {
  const primero = problemas.value[0]
  if (primero) irA(primero.hoja, primero.celda)
  else if (filaSenalada.value) irA(filaSenalada.value.hoja, `A${filaSenalada.value.fila ?? 1}`)
}

function volverACargar() {
  emit('reintentar', Object.values(correcciones))
}
</script>

<template>
  <Modal
    :model-value="modelValue"
    :titulo="`Corregir «${archivo?.name ?? 'archivo'}»`"
    max-width="max-w-6xl"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <div class="flex max-h-[72vh] flex-col gap-3 lg:h-[72vh] lg:flex-row">
      <!-- Panel de celdas señaladas -->
      <aside class="flex shrink-0 flex-col gap-2.5 overflow-y-auto lg:w-80">
        <div
          class="rounded border px-3 py-2.5 text-[12.5px] leading-relaxed"
          style="border-color: color-mix(in srgb, var(--color-critico) 35%, transparent); background: var(--color-critico-fondo)"
        >
          {{ fallo?.mensaje ?? 'El archivo no se pudo leer.' }}
        </div>

        <p v-if="problemas.length > 0" class="text-[12px] font-semibold text-(--color-tinta-2)">
          {{ problemas.length }} {{ problemas.length === 1 ? 'celda no coincide' : 'celdas no coinciden' }} con el catálogo
          <span v-if="pendientes > 0" style="color: var(--color-critico)"> · faltan {{ pendientes }}</span>
        </p>
        <p v-else class="text-[12px] leading-relaxed text-(--color-apagado)">
          Haga clic en cualquier celda de la tabla para cambiar su valor.
        </p>

        <button
          v-for="p in problemas"
          :key="clave(p.hoja, p.celda)"
          type="button"
          class="rounded border p-2.5 text-left transition-colors hover:bg-(--color-hundido)/40"
          :class="
            seleccion && clave(seleccion.hoja, seleccion.celda) === clave(p.hoja, p.celda)
              ? 'border-(--color-sello)'
              : 'border-(--color-regla)'
          "
          @click="irA(p.hoja, p.celda)"
        >
          <div class="flex items-center justify-between gap-2">
            <span class="text-[11.5px] font-semibold text-(--color-apagado)">{{ p.hoja }} · {{ p.celda }}</span>
            <Icon
              :name="correccionDe(p.hoja, p.celda) ? 'check-circle' : 'error-circle'"
              :size="16"
              :style="{ color: correccionDe(p.hoja, p.celda) ? 'var(--color-bien)' : 'var(--color-critico)' }"
            />
          </div>
          <p class="mt-1 text-[12.5px] text-(--color-tinta-2)">
            Dice: <span class="line-through decoration-(--color-critico)/60">«{{ p.encontrado }}»</span>
          </p>
          <p v-if="correccionDe(p.hoja, p.celda)" class="mt-0.5 text-[12.5px] font-semibold" style="color: var(--color-bien)">
            → {{ correccionDe(p.hoja, p.celda)?.valor }}
          </p>
          <p v-else-if="!p.sugerencia" class="mt-0.5 text-[12px] text-(--color-apagado)">Sin sugerencia: elija el estándar.</p>
        </button>
      </aside>

      <!-- Editor + tabla -->
      <section class="flex min-h-0 min-w-0 flex-1 flex-col gap-2.5">
        <div v-if="seleccion" class="rounded border border-(--color-regla) bg-(--color-superficie-2) p-2.5">
          <div class="mb-1.5 flex flex-wrap items-center gap-x-3 gap-y-1">
            <span class="text-[12px] font-semibold">{{ seleccion.hoja }} · {{ seleccion.celda }}</span>
            <span v-if="seleccion.original" class="truncate text-[11.5px] text-(--color-apagado)">
              Original: «{{ seleccion.original }}»
            </span>
          </div>

          <div v-if="problemaSeleccionado?.sugerencia" class="mb-2 flex flex-wrap items-center gap-2 text-[12.5px]">
            <Icon name="bulb" :size="16" style="color: var(--color-alerta)" />
            <span>Sugerencia: <strong>{{ problemaSeleccionado.sugerencia }}</strong></span>
            <AppButton
              variante="text"
              @click="
                valorEditado = problemaSeleccionado.sugerencia;
                aplicarEdicion()
              "
            >
              Usar sugerencia
            </AppButton>
          </div>

          <div class="flex flex-col gap-2 sm:flex-row">
            <select
              v-if="problemaSeleccionado"
              v-model="valorEditado"
              class="min-w-0 flex-1 rounded border border-(--color-regla) bg-(--color-superficie) px-2.5 py-2 text-[13px]"
              @change="aplicarEdicion"
            >
              <option :value="problemaSeleccionado.encontrado" disabled>Elija el estándar…</option>
              <option v-for="nombre in estandares" :key="nombre" :value="nombre">{{ nombre }}</option>
            </select>
            <input
              v-else
              v-model="valorEditado"
              class="min-w-0 flex-1 rounded border border-(--color-regla) bg-(--color-superficie) px-2.5 py-2 text-[13px]"
              placeholder="Nuevo valor de la celda"
              @keydown.enter.prevent="aplicarEdicion"
            />
            <div class="flex gap-2">
              <AppButton v-if="!problemaSeleccionado" variante="outlined" icono="check" @click="aplicarEdicion">Aplicar</AppButton>
              <AppButton
                v-if="correccionDe(seleccion.hoja, seleccion.celda)"
                variante="text"
                tono="tinta"
                @click="
                  deshacer(seleccion.hoja, seleccion.celda);
                  valorEditado = seleccion.original
                "
              >
                Deshacer
              </AppButton>
            </div>
          </div>
        </div>

        <div v-if="leyendo" class="flex flex-1 flex-col items-center justify-center gap-2 py-10 text-(--color-apagado)">
          <svg class="h-6 w-6 animate-spin" viewBox="0 0 24 24" fill="none">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" />
            <path class="opacity-80" fill="currentColor" d="M12 2a10 10 0 0 1 10 10h-3a7 7 0 0 0-7-7z" />
          </svg>
          <span class="text-[13px]">Leyendo el archivo…</span>
        </div>

        <div v-else-if="errorLectura" class="flex flex-1 items-center justify-center p-6 text-center text-[13px] text-(--color-apagado)">
          {{ errorLectura }}
        </div>

        <template v-else-if="hoja">
          <div v-if="hojas.length > 1" class="flex shrink-0 gap-1 overflow-x-auto border-b border-(--color-regla)">
            <button
              v-for="(h, i) in hojas"
              :id="`pestana-${i}`"
              :key="h.nombre"
              type="button"
              class="shrink-0 border-b-2 px-3 py-1.5 text-[12px] font-medium whitespace-nowrap"
              :class="i === hojaActiva ? 'border-(--color-sello) text-(--color-sello)' : 'border-transparent text-(--color-apagado)'"
              @click="hojaActiva = i"
            >
              {{ h.nombre }}
              <span
                v-if="problemas.some((p) => normalizar(p.hoja) === normalizar(h.nombre))"
                class="ml-1 inline-block h-1.5 w-1.5 rounded-full align-middle"
                style="background: var(--color-critico)"
              />
            </button>
          </div>

          <div class="min-h-[240px] flex-1 overflow-auto rounded border border-(--color-regla)">
            <table class="border-separate border-spacing-0 text-left text-[12px]">
              <thead>
                <tr>
                  <th class="sticky top-0 left-0 z-20 border-r border-b border-(--color-regla) bg-(--color-superficie-2)" />
                  <th
                    v-for="c in totalColumnas"
                    :key="c"
                    class="sticky top-0 z-10 min-w-16 border-b border-(--color-regla) bg-(--color-superficie-2) px-2 py-1 text-center text-[11px] font-semibold text-(--color-apagado)"
                  >
                    {{ letra(c - 1) }}
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(_, i) in hoja.filas" :key="i">
                  <th
                    class="sticky left-0 z-10 border-r border-b border-(--color-regla) bg-(--color-superficie-2) px-2 py-1 text-right text-[11px] font-semibold text-(--color-apagado)"
                  >
                    {{ i + 1 }}
                  </th>
                  <td
                    v-for="c in totalColumnas"
                    :id="`celda-${hojaActiva}-${i}-${c - 1}`"
                    :key="c"
                    class="max-w-72 cursor-pointer truncate border-b border-(--color-regla) px-2 py-1 hover:bg-(--color-hundido)/50"
                    :class="claseCelda(i, c - 1)"
                    :title="textoCelda(i, c - 1)"
                    @click="seleccionar(i, c - 1)"
                  >
                    {{ textoCelda(i, c - 1) }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <p v-if="hoja.truncada" class="text-[11.5px] text-(--color-apagado)">
            Se muestran las primeras {{ hoja.filas.length }} filas de esta hoja.
          </p>
        </template>
      </section>
    </div>

    <template #footer>
      <div class="flex flex-col-reverse gap-2 sm:flex-row sm:items-center sm:justify-between">
        <p class="text-[12px] text-(--color-apagado)">
          El archivo original no se modifica: las correcciones se aplican al leerlo y quedan registradas.
        </p>
        <div class="flex justify-end gap-2">
          <AppButton variante="text" tono="tinta" @click="emit('update:modelValue', false)">Cancelar</AppButton>
          <AppButton icono="upload" :cargando="cargando" :disabled="totalCorrecciones === 0" @click="volverACargar">
            Volver a cargar{{ totalCorrecciones > 0 ? ` (${totalCorrecciones})` : '' }}
          </AppButton>
        </div>
      </div>
    </template>
  </Modal>
</template>
