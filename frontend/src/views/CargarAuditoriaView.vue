<script setup lang="ts">
import { computed, defineAsyncComponent, ref } from 'vue'
import { useRouter } from 'vue-router'

import { confirmar } from '@/composables/useDialogs'
import { ApiError } from '@/core/http/apiClient'
import type { CorreccionCelda, OpcionesCarga } from '@/data/auditorias.repository'
import { useCrearSedeMutation, useEstandaresQuery, useSedesQuery } from '@/queries/useCatalogos'
import {
  useArchivoAuditoriaMutation,
  useAuditoriasQuery,
  useCargarAuditoriasMutation,
  useEliminarAuditoriaMutation,
} from '@/queries/useAuditorias'
import { useUiStore } from '@/stores/ui.store'
import AppButton from '@/components/ui/AppButton.vue'
import AppInput from '@/components/ui/AppInput.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import FileDropzone from '@/components/ui/FileDropzone.vue'
import Icon from '@/components/ui/Icon.vue'
import IconButton from '@/components/ui/IconButton.vue'
import Modal from '@/components/ui/Modal.vue'
import Notice from '@/components/ui/Notice.vue'
import Panel from '@/components/ui/Panel.vue'
import SectionHeading from '@/components/ui/SectionHeading.vue'
import StatCard from '@/components/ui/StatCard.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import PageHeader from '@/components/layout/PageHeader.vue'

/** Cargar autoevaluaciones: la entrada principal del sistema. Mirror de
 * PantallaCargarAuditoria (pantallas/cargar_auditoria.dart). */
const router = useRouter()
const ui = useUiStore()

const archivos = ref<File[]>([])
const subiendo = ref(false)
const resultado = ref<Record<string, unknown> | null>(null)
const error = ref<string | null>(null)

const cargarMutation = useCargarAuditoriasMutation()
const sedesQuery = useSedesQuery()
const crearSedeMutation = useCrearSedeMutation()

function alElegir(nuevos: File[]) {
  archivos.value = nuevos
  resultado.value = null
  error.value = null
}

function quitar(i: number) {
  archivos.value.splice(i, 1)
}

function mensajeDe(e: unknown): string {
  return e instanceof ApiError ? e.mensaje : `No se pudo procesar: ${e}`
}

async function subir() {
  if (archivos.value.length === 0) return

  subiendo.value = true
  error.value = null
  resultado.value = null

  try {
    const r = await cargarMutation.mutateAsync({ archivos: archivos.value })
    resultado.value = r

    const cargadas = (r.cargadas as Record<string, unknown>[] | undefined) ?? []
    const nombresCargados = new Set(cargadas.map((c) => c.archivo))
    archivos.value = archivos.value.filter((f) => !nombresCargados.has(f.name))

    // Si algún archivo falló por su contenido, se abre de una vez la vista
    // previa para corregirlo: es lo que la persona iba a tener que hacer.
    const corregible = ((r.fallidas as Record<string, unknown>[] | undefined) ?? []).find(esCorregible)
    if (corregible) abrirCorreccion(corregible)
  } catch (e) {
    error.value = mensajeDe(e)
  } finally {
    subiendo.value = false
  }
}

// Lo que ya se resolvió para cada archivo (la sede elegida, las celdas
// corregidas) se conserva entre reintentos: si después de elegir la sede
// aparece una celda por corregir, no hay que volver a elegirla.
const opcionesPorArchivo: Record<string, OpcionesCarga> = {}

function reintentarConSede(nombreArchivo: string, sedeId: number) {
  return reintentar(nombreArchivo, { sedeId })
}

/** Vuelve a subir un archivo que falló. Devuelve el fallo nuevo, si lo hubo. */
async function reintentar(nombreArchivo: string, opciones: OpcionesCarga): Promise<Record<string, unknown> | null> {
  const archivo = archivos.value.find((f) => f.name === nombreArchivo)
  if (!archivo) return null

  const combinadas = { ...opcionesPorArchivo[nombreArchivo], ...opciones }
  opcionesPorArchivo[nombreArchivo] = combinadas
  subiendo.value = true

  try {
    const r = await cargarMutation.mutateAsync({ archivos: [archivo], opciones: combinadas })

    const nuevasCargadas = (r.cargadas as Record<string, unknown>[] | undefined) ?? []
    const nuevasFallidas = (r.fallidas as Record<string, unknown>[] | undefined) ?? []
    const previas = resultado.value ?? {}
    const cargadasPrevias = (previas.cargadas as Record<string, unknown>[] | undefined) ?? []
    const fallidasPrevias = ((previas.fallidas as Record<string, unknown>[] | undefined) ?? []).filter(
      (f) => f.archivo !== nombreArchivo,
    )

    resultado.value = {
      cargadas: [...cargadasPrevias, ...nuevasCargadas],
      fallidas: [...fallidasPrevias, ...nuevasFallidas],
    }

    if (nuevasCargadas.length > 0) {
      archivos.value = archivos.value.filter((f) => f.name !== nombreArchivo)
      delete opcionesPorArchivo[nombreArchivo]
      return null
    }
    return nuevasFallidas[0] ?? null
  } catch (e) {
    ui.mostrar(mensajeDe(e), { error: true })
    return null
  } finally {
    subiendo.value = false
  }
}

// ── Corrección en la vista previa ──────────────────────────────────────────
const estandaresQuery = useEstandaresQuery()
const nombresEstandar = computed(() => (estandaresQuery.data.value ?? []).map((e) => e.nombre))

const CorregirCargaModal = defineAsyncComponent(() => import('@/components/ui/CorregirCargaModal.vue'))
const correccion = ref<{ abierto: boolean; archivo: File | null; fallo: Record<string, unknown> | null }>({
  abierto: false,
  archivo: null,
  fallo: null,
})

/** Fallos que se arreglan cambiando celdas: estándares que no coinciden o una estructura mal formada. */
function esCorregible(fallo: Record<string, unknown>): boolean {
  return fallo.tipo === 'celdas_no_reconocidas' || (fallo.tipo === 'estructura_invalida' && fallo.hoja != null)
}

function abrirCorreccion(fallo: Record<string, unknown>) {
  const archivo = archivos.value.find((f) => f.name === fallo.archivo) ?? null
  if (!archivo) return
  correccion.value = { abierto: true, archivo, fallo }
}

async function reintentarConCorrecciones(correcciones: CorreccionCelda[]) {
  const archivo = correccion.value.archivo
  if (!archivo) return

  const nuevoFallo = await reintentar(archivo.name, { correcciones })

  if (nuevoFallo === null) {
    correccion.value.abierto = false
    const plural = correcciones.length === 1 ? 'corrección' : 'correcciones'
    ui.mostrar(`«${archivo.name}» se cargó con ${correcciones.length} ${plural}.`)
  } else if (esCorregible(nuevoFallo)) {
    // Quedan celdas: se actualiza el panel sin perder lo ya corregido.
    correccion.value.fallo = nuevoFallo
  } else {
    // Se corrigió el contenido pero falta otra cosa (p. ej. elegir la sede).
    correccion.value.abierto = false
    if (nuevoFallo.tipo === 'sede_no_identificada') abrirSelectorDeSede(nuevoFallo)
  }
}

// ── Diálogo "Elegir sede" ──────────────────────────────────────────────────
const dialogoSede = ref<{
  abierto: boolean
  archivo: string
  textoEncontrado: string | null
  sugerencia: { id: number; nombre: string } | null
  creandoNueva: boolean
}>({ abierto: false, archivo: '', textoEncontrado: null, sugerencia: null, creandoNueva: false })
const nuevoCodigo = ref('')
const nuevoNombre = ref('')
const errorCreacionSede = ref<string | null>(null)

function abrirSelectorDeSede(fallo: Record<string, unknown>) {
  dialogoSede.value = {
    abierto: true,
    archivo: fallo.archivo as string,
    textoEncontrado: (fallo.texto_encontrado as string | undefined) ?? null,
    sugerencia: (fallo.sugerencia as { id: number; nombre: string } | undefined) ?? null,
    creandoNueva: false,
  }
  nuevoCodigo.value = ''
  nuevoNombre.value = ''
  errorCreacionSede.value = null
}

function elegirSedeExistente(sedeId: number) {
  dialogoSede.value.abierto = false
  reintentarConSede(dialogoSede.value.archivo, sedeId)
}

async function crearYUsarSede() {
  const codigo = nuevoCodigo.value.trim()
  const nombre = nuevoNombre.value.trim()
  if (!codigo || !nombre) {
    errorCreacionSede.value = 'Complete el código y el nombre.'
    return
  }

  errorCreacionSede.value = null

  try {
    const sede = await crearSedeMutation.mutateAsync({ codigo, nombre })
    const archivo = dialogoSede.value.archivo
    dialogoSede.value.abierto = false
    await reintentarConSede(archivo, sede.id)
  } catch (e) {
    errorCreacionSede.value = mensajeDe(e)
  }
}

const cargadas = computed(() => (resultado.value?.cargadas as Record<string, unknown>[] | undefined) ?? [])
const fallidas = computed(() => (resultado.value?.fallidas as Record<string, unknown>[] | undefined) ?? [])

function detalleFallo(fallo: Record<string, unknown>): string {
  const partes: string[] = []
  if (fallo.hoja) partes.push(`Hoja: ${fallo.hoja}`)
  if (fallo.fila) partes.push(`Fila: ${fallo.fila}`)
  if (fallo.encontrado) partes.push(`Dice: «${fallo.encontrado}»`)
  return partes.join('   ·   ')
}

// ── Archivos ya cargados: listado, previsualización y borrado ─────────────
const auditoriasQuery = useAuditoriasQuery()
const eliminarMutation = useEliminarAuditoriaMutation()
const archivoMutation = useArchivoAuditoriaMutation()

function sedeDe(a: Record<string, unknown>): Record<string, unknown> {
  return (a.sede as Record<string, unknown> | undefined) ?? {}
}

function colorEstado(estado: string): string {
  return (
    {
      publicada: 'var(--color-bien)',
      por_confirmar: 'var(--color-alerta)',
      fallida: 'var(--color-critico)',
    }[estado] ?? 'var(--color-apagado)'
  )
}

function etiquetaEstado(estado: string): string {
  return (
    {
      publicada: 'Publicada',
      por_confirmar: 'Por confirmar',
      procesando: 'Procesando',
      fallida: 'Fallida',
    }[estado] ?? estado
  )
}

// ExcelJS pesa casi 1 MB: se carga en su propio chunk, aparte del de esta
// pantalla, y solo cuando alguien abre una previsualización por primera vez.
const SpreadsheetPreviewModal = defineAsyncComponent(() => import('@/components/ui/SpreadsheetPreviewModal.vue'))
const vistaPreviaMontada = ref(false)

const vistaPrevia = ref<{
  abierto: boolean
  nombre: string
  buffer: ArrayBuffer | null
  cargando: boolean
  error: string | null
}>({ abierto: false, nombre: '', buffer: null, cargando: false, error: null })

const previsualizandoId = ref<number | null>(null)

async function previsualizar(auditoria: Record<string, unknown>) {
  const nombreSede = (sedeDe(auditoria).nombre as string) ?? 'Archivo'
  previsualizandoId.value = auditoria.id as number
  vistaPreviaMontada.value = true
  vistaPrevia.value = { abierto: true, nombre: nombreSede, buffer: null, cargando: true, error: null }

  try {
    const blob = await archivoMutation.mutateAsync(auditoria.id as number)
    vistaPrevia.value = {
      abierto: true,
      nombre: nombreSede,
      buffer: await blob.arrayBuffer(),
      cargando: false,
      error: null,
    }
  } catch (e) {
    vistaPrevia.value = {
      abierto: true,
      nombre: nombreSede,
      buffer: null,
      cargando: false,
      error: e instanceof ApiError ? e.mensaje : mensajeDe(e),
    }
  } finally {
    previsualizandoId.value = null
  }
}

// Cada tarjeta solo marca su propio botón como «borrando»: `isPending` de la
// mutación es global y marcaría todas las filas a la vez.
const eliminandoId = ref<number | null>(null)

async function eliminarAuditoriaCargada(auditoria: Record<string, unknown>) {
  const nombreSede = (sedeDe(auditoria).nombre as string) ?? 'esta sede'

  const ok = await confirmar({
    titulo: '¿Eliminar esta carga?',
    mensaje: `${nombreSede} · versión ${auditoria.version}. El archivo original se borra por completo y esto no se puede deshacer.`,
    textoConfirmar: 'Eliminar',
    tono: 'critico',
  })
  if (!ok) return

  eliminandoId.value = auditoria.id as number

  try {
    await eliminarMutation.mutateAsync({ auditoriaId: auditoria.id as number })
    ui.mostrar(`${nombreSede} eliminada.`)
  } catch (e) {
    // Hay hallazgos que dependen de ella: en vez de un error, se pregunta.
    if (e instanceof ApiError && e.codigo === 409 && e.detalles?.requiere_confirmacion === true) {
      const forzar = await confirmar({
        titulo: '¿Eliminar de todas formas?',
        mensaje: e.mensaje,
        textoConfirmar: 'Eliminar',
        tono: 'critico',
      })
      if (forzar) {
        try {
          await eliminarMutation.mutateAsync({ auditoriaId: auditoria.id as number, forzar: true })
          ui.mostrar(`${nombreSede} eliminada.`)
        } catch (e2) {
          ui.mostrar(mensajeDe(e2), { error: true })
        }
      }
    } else {
      ui.mostrar(mensajeDe(e), { error: true })
    }
  } finally {
    eliminandoId.value = null
  }
}
</script>

<template>
  <PageHeader titulo="Cargar auditoría">
    <template #acciones>
      <IconButton
        icono="refresh"
        titulo="Actualizar"
        :cargando="auditoriasQuery.isFetching.value"
        @click="auditoriasQuery.refetch()"
      />
    </template>
  </PageHeader>

  <div class="mx-auto max-w-3xl space-y-5 p-4 pb-10 sm:p-6">
    <Notice
      icono="file"
      mensaje="Suba las autoevaluaciones de las sedes: los archivos «SUH <sede>.xlsx». La matriz mensual de seguimiento se sube desde la pantalla del corte."
    />

    <FileDropzone
      :disabled="subiendo"
      :texto="archivos.length === 0 ? 'Elegir archivos' : `Cambiar selección (${archivos.length})`"
      @files="alElegir"
    />

    <template v-if="archivos.length > 0">
      <Panel sin-padding>
        <div
          v-for="(archivo, i) in archivos"
          :key="archivo.name"
          class="flex items-center gap-3 px-3.5 py-2.5"
          :class="i > 0 ? 'border-t border-(--color-regla)' : ''"
        >
          <Icon name="file-table" :size="20" class="shrink-0 text-(--color-sello)" />
          <div class="min-w-0 flex-1">
            <p class="truncate text-[13.5px]">{{ archivo.name }}</p>
            <p class="text-[11.5px] text-(--color-apagado)">{{ (archivo.size / 1048576).toFixed(1) }} MB</p>
          </div>
          <button type="button" :disabled="subiendo" class="text-(--color-apagado) hover:text-(--color-tinta)" @click="quitar(i)">
            <Icon name="close" :size="18" />
          </button>
        </div>
      </Panel>

      <AppButton icono="upload" :cargando="subiendo" block @click="subir">
        {{ subiendo ? 'Procesando…' : 'Subir y procesar' }}
      </AppButton>
      <p v-if="subiendo" class="text-[12px] leading-relaxed text-(--color-apagado)">
        Se está leyendo el archivo y enfrentando contra los hallazgos vigentes de la sede. Puede tardar unos segundos.
      </p>
    </template>

    <Notice v-if="error" icono="error-circle" color="var(--color-critico)" :mensaje="error" />

    <template v-if="resultado">
      <div v-if="cargadas.length > 0">
        <SectionHeading texto="Procesadas" />
        <div class="space-y-2.5">
          <Panel v-for="carga in cargadas" :key="carga.archivo as string">
            <div class="flex items-start justify-between gap-2">
              <p class="text-[15px] font-semibold">{{ carga.sede }}</p>
              <StatusBadge :texto="`Versión ${carga.version}`" color="var(--color-sello)" fondo="var(--color-sello-suave)" />
            </div>
            <p class="text-[11.5px] text-(--color-apagado)">{{ carga.archivo }}</p>

            <div class="mt-3.5 grid grid-cols-3 gap-3">
              <StatCard
                :valor="String((carga.extraccion as Record<string, unknown> | undefined)?.hallazgos ?? 0)"
                rotulo="Hallazgos&#10;extraídos"
              />
              <StatCard
                :valor="String((carga.extraccion as Record<string, unknown> | undefined)?.criterios ?? 0)"
                rotulo="Criterios&#10;evaluados"
              />
              <StatCard
                :valor="String((carga.reconciliacion as Record<string, unknown> | undefined)?.pendientes_de_decision ?? 0)"
                rotulo="Decisiones&#10;pendientes"
                :color="
                  ((carga.reconciliacion as Record<string, unknown> | undefined)?.pendientes_de_decision as number) > 0
                    ? 'var(--color-alerta)'
                    : 'var(--color-apagado)'
                "
              />
            </div>

            <Notice
              v-for="(advertencia, j) in ((carga.extraccion as Record<string, unknown> | undefined)?.advertencias as string[] | undefined) ?? []"
              :key="j"
              class="mt-3"
              icono="warning"
              color="var(--color-alerta)"
              :mensaje="advertencia"
            />

            <AppButton
              icono="check-circle"
              class="mt-4"
              block
              @click="router.push({ name: 'confirmar-cruce', params: { id: carga.auditoria_id as number } })"
            >
              {{
                ((carga.reconciliacion as Record<string, unknown> | undefined)?.pendientes_de_decision as number) > 0
                  ? 'Revisar el cruce'
                  : 'Revisar y publicar'
              }}
            </AppButton>
          </Panel>
        </div>
      </div>

      <div v-if="fallidas.length > 0">
        <SectionHeading texto="No se pudieron leer" />
        <div class="space-y-2.5">
          <div
            v-for="fallo in fallidas"
            :key="fallo.archivo as string"
            class="rounded border p-3.5"
            style="border-color: color-mix(in srgb, var(--color-critico) 40%, transparent)"
          >
            <div class="flex items-center gap-2">
              <Icon name="block" :size="17" class="shrink-0" style="color: var(--color-critico)" />
              <p class="truncate text-[13.5px] font-semibold">{{ fallo.archivo }}</p>
            </div>
            <p class="mt-2 text-[13px] leading-relaxed text-(--color-tinta-2)">
              {{ fallo.mensaje ?? 'No se pudo leer el archivo.' }}
            </p>
            <p v-if="fallo.hoja || fallo.fila" class="mt-2 text-[12px] font-semibold" style="color: var(--color-critico)">
              {{ detalleFallo(fallo) }}
            </p>
            <p
              v-if="fallo.tipo === 'sede_no_identificada' && fallo.sugerencia"
              class="mt-2 flex items-center gap-1.5 text-[12.5px] text-(--color-tinta-2)"
            >
              <Icon name="bulb" :size="15" style="color: var(--color-alerta)" />
              ¿Quiso decir <strong>{{ (fallo.sugerencia as Record<string, unknown>).nombre }}</strong>?
            </p>
            <AppButton
              v-if="fallo.tipo === 'sede_no_identificada'"
              variante="outlined"
              icono="location-plus"
              class="mt-3"
              @click="abrirSelectorDeSede(fallo)"
            >
              Elegir sede
            </AppButton>
            <AppButton
              v-if="esCorregible(fallo)"
              variante="outlined"
              icono="edit"
              class="mt-3"
              @click="abrirCorreccion(fallo)"
            >
              Corregir en la vista previa
            </AppButton>
          </div>
        </div>
      </div>
    </template>

    <div>
      <SectionHeading texto="Archivos cargados" />

      <div v-if="auditoriasQuery.isPending.value" class="py-8 text-center text-[13px] text-(--color-apagado)">
        Cargando…
      </div>

      <ErrorState
        v-else-if="auditoriasQuery.isError.value"
        :error="auditoriasQuery.error.value"
        :reintentar="() => auditoriasQuery.refetch()"
      />

      <p
        v-else-if="auditoriasQuery.data.value && auditoriasQuery.data.value.length === 0"
        class="py-6 text-center text-[13px] text-(--color-apagado)"
      >
        Todavía no hay nada cargado. Suba una autoevaluación arriba para verla aquí.
      </p>

      <div v-else-if="auditoriasQuery.data.value" class="space-y-2.5">
        <Panel v-for="auditoria in auditoriasQuery.data.value" :key="auditoria.id as number">
          <div class="flex items-start justify-between gap-2">
            <p class="text-[15px] font-semibold">{{ sedeDe(auditoria).nombre ?? '—' }}</p>
            <div class="flex shrink-0 items-center gap-0.5">
              <IconButton
                v-if="(auditoria.archivos_count as number) > 0"
                icono="eye"
                titulo="Previsualizar"
                :cargando="previsualizandoId === auditoria.id"
                @click="previsualizar(auditoria)"
              />
              <IconButton
                icono="trash"
                tono="critico"
                titulo="Eliminar"
                :cargando="eliminandoId === auditoria.id"
                @click="eliminarAuditoriaCargada(auditoria)"
              />
            </div>
          </div>
          <div class="mt-1.5 flex flex-wrap gap-1.5">
            <StatusBadge :texto="`Versión ${auditoria.version}`" color="var(--color-sello)" fondo="var(--color-sello-suave)" />
            <StatusBadge :texto="etiquetaEstado(auditoria.estado as string)" :color="colorEstado(auditoria.estado as string)" />
            <StatusBadge v-if="auditoria.origen === 'linea_base'" texto="Línea base" color="var(--color-tinta-2)" />
            <StatusBadge v-if="auditoria.periodo" :texto="auditoria.periodo as string" color="var(--color-apagado)" />
          </div>
          <p class="mt-2.5 text-[12px] text-(--color-apagado)">
            {{ auditoria.criterios_count ?? 0 }} criterios evaluados · {{ auditoria.apariciones_count ?? 0 }} hallazgos vinculados
          </p>
        </Panel>
      </div>
    </div>
  </div>

  <SpreadsheetPreviewModal
    v-if="vistaPreviaMontada"
    v-model="vistaPrevia.abierto"
    :nombre-archivo="vistaPrevia.nombre"
    :buffer="vistaPrevia.buffer"
    :cargando="vistaPrevia.cargando"
    :error="vistaPrevia.error"
  />

  <CorregirCargaModal
    v-if="correccion.archivo"
    v-model="correccion.abierto"
    :archivo="correccion.archivo"
    :fallo="correccion.fallo"
    :estandares="nombresEstandar"
    :cargando="subiendo"
    @reintentar="reintentarConCorrecciones"
  />

  <Modal v-model="dialogoSede.abierto" titulo="Elegir sede" max-width="max-w-sm">
    <p v-if="dialogoSede.textoEncontrado" class="mb-3.5 text-[12.5px] leading-relaxed text-(--color-apagado)">
      El archivo dice: «{{ dialogoSede.textoEncontrado }}»
    </p>

    <template v-if="!dialogoSede.creandoNueva">
      <button
        v-if="dialogoSede.sugerencia"
        type="button"
        class="mb-3 flex w-full items-center gap-2.5 rounded border p-2.5 text-left hover:bg-(--color-hundido)/40"
        style="border-color: var(--color-alerta); background: var(--color-alerta-fondo)"
        @click="elegirSedeExistente(dialogoSede.sugerencia.id)"
      >
        <Icon name="bulb" :size="18" style="color: var(--color-alerta)" />
        <span class="flex flex-col">
          <span class="text-[11.5px] font-semibold text-(--color-alerta)">Sugerencia</span>
          <span class="text-[13.5px]">{{ dialogoSede.sugerencia.nombre }}</span>
        </span>
      </button>
      <p v-if="(sedesQuery.data.value ?? []).length === 0" class="text-[13px] text-(--color-apagado)">
        Todavía no hay sedes registradas.
      </p>
      <div v-else class="max-h-64 divide-y divide-(--color-regla) overflow-y-auto">
        <button
          v-for="sede in sedesQuery.data.value ?? []"
          :key="sede.id"
          type="button"
          class="flex w-full flex-col py-2.5 text-left hover:bg-(--color-hundido)/40"
          @click="elegirSedeExistente(sede.id)"
        >
          <span class="text-[13.5px]">{{ sede.nombre }}</span>
          <span class="text-[11.5px] text-(--color-apagado)">{{ sede.codigo }}</span>
        </button>
      </div>
      <AppButton variante="outlined" icono="plus" class="mt-3.5" block @click="dialogoSede.creandoNueva = true">
        Registrar una sede nueva
      </AppButton>
    </template>

    <template v-else>
      <div class="flex flex-col gap-3">
        <AppInput v-model="nuevoCodigo" label="Código" placeholder="COMUNEROS" />
        <AppInput v-model="nuevoNombre" label="Nombre" placeholder="Centro de Salud Comuneros" />
        <p v-if="errorCreacionSede" class="text-[12.5px]" style="color: var(--color-critico)">{{ errorCreacionSede }}</p>
        <button
          type="button"
          class="text-left text-[13px] text-(--color-sello)"
          @click="dialogoSede.creandoNueva = false"
        >
          ← Elegir de la lista en vez de crear
        </button>
      </div>
    </template>

    <template #footer>
      <div class="flex justify-end gap-2">
        <AppButton variante="text" tono="tinta" @click="dialogoSede.abierto = false">Cancelar</AppButton>
        <AppButton v-if="dialogoSede.creandoNueva" :cargando="crearSedeMutation.isPending.value" @click="crearYUsarSede">
          Crear y usar
        </AppButton>
      </div>
    </template>
  </Modal>
</template>
