<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useRouter } from 'vue-router'

import type { AuditoriaConFotos, FotoEvidencia } from '@/data/evidencias.repository'
import { Formato } from '@/core/format'
import { useEvidenciasQuery } from '@/queries/useEvidencias'
import AppButton from '@/components/ui/AppButton.vue'
import Chip from '@/components/ui/Chip.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import Icon from '@/components/ui/Icon.vue'
import IconButton from '@/components/ui/IconButton.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import PageHeader from '@/components/layout/PageHeader.vue'

/**
 * Evidencias: las fotos del registro fotográfico de cada auditoría cargada
 * (las hojas FOTOS, EVIDENCIAS, FOTOGRAFIAS… de la autoevaluación), para
 * verlas sin abrir el Excel.
 */
const router = useRouter()
const evidenciasQuery = useEvidenciasQuery()

// ── Filtro por sede ────────────────────────────────────────────────────────
const sedeElegida = ref<number | null>(null)

const sedes = computed(() => {
  const mapa = new Map<number, { id: number; nombre: string; fotos: number }>()
  for (const grupo of evidenciasQuery.data.value?.grupos ?? []) {
    const sede = grupo.auditoria.sede
    if (!sede) continue
    const actual = mapa.get(sede.id) ?? { id: sede.id, nombre: sede.nombre, fotos: 0 }
    actual.fotos += grupo.fotos.length
    mapa.set(sede.id, actual)
  }
  return [...mapa.values()].sort((a, b) => a.nombre.localeCompare(b.nombre, 'es'))
})

const grupos = computed(() =>
  (evidenciasQuery.data.value?.grupos ?? []).filter(
    (g) => sedeElegida.value === null || g.auditoria.sede?.id === sedeElegida.value,
  ),
)

const totalVisible = computed(() => grupos.value.reduce((n, g) => n + g.fotos.length, 0))

// ── Visor ──────────────────────────────────────────────────────────────────
// Recorre todas las fotos visibles en orden, pasando de una auditoría a la
// siguiente sin cerrar el visor.
const secuencia = computed(() =>
  grupos.value.flatMap((g) => g.fotos.map((foto, i) => ({ foto, auditoria: g.auditoria, posicion: i + 1, deGrupo: g.fotos.length }))),
)
const indiceAbierto = ref<number | null>(null)
const actual = computed(() => (indiceAbierto.value === null ? null : (secuencia.value[indiceAbierto.value] ?? null)))
const cargandoImagen = ref(false)

function abrir(foto: FotoEvidencia) {
  indiceAbierto.value = secuencia.value.findIndex((s) => s.foto.id === foto.id)
}

function cerrar() {
  indiceAbierto.value = null
}

function mover(paso: number) {
  if (indiceAbierto.value === null) return
  const total = secuencia.value.length
  indiceAbierto.value = (indiceAbierto.value + paso + total) % total
}

watch(actual, (nuevo, anterior) => {
  if (nuevo && nuevo.foto.id !== anterior?.foto.id) cargandoImagen.value = true
})

function alTeclear(evento: KeyboardEvent) {
  if (indiceAbierto.value === null) return
  if (evento.key === 'Escape') cerrar()
  else if (evento.key === 'ArrowRight') mover(1)
  else if (evento.key === 'ArrowLeft') mover(-1)
}

window.addEventListener('keydown', alTeclear)
onBeforeUnmount(() => window.removeEventListener('keydown', alTeclear))

function etiquetaAuditoria(a: AuditoriaConFotos): string {
  const partes = [`Versión ${a.version}`]
  if (a.periodo) partes.push(Formato.periodo(a.periodo))
  return partes.join(' · ')
}

function ubicacion(foto: FotoEvidencia): string {
  return foto.celda ? `Hoja «${foto.hoja}», celda ${foto.celda}` : `Hoja «${foto.hoja}»`
}

function nombreDescarga(foto: FotoEvidencia, a: AuditoriaConFotos): string {
  const sede = (a.sede?.nombre ?? 'sede').replace(/[^\p{L}\p{N}]+/gu, '_')
  return `${sede}_v${a.version}_foto_${String(foto.orden).padStart(3, '0')}`
}
</script>

<template>
  <PageHeader titulo="Evidencias">
    <template #acciones>
      <IconButton
        icono="refresh"
        titulo="Actualizar"
        :cargando="evidenciasQuery.isFetching.value"
        @click="evidenciasQuery.refetch()"
      />
    </template>
  </PageHeader>

  <div v-if="evidenciasQuery.isPending.value" class="flex justify-center py-20 text-(--color-apagado)">Cargando…</div>

  <ErrorState
    v-else-if="evidenciasQuery.isError.value"
    :error="evidenciasQuery.error.value"
    :reintentar="() => evidenciasQuery.refetch()"
  />

  <EmptyState
    v-else-if="(evidenciasQuery.data.value?.total ?? 0) === 0"
    icono="image"
    titulo="Todavía no hay fotos de evidencia"
    detalle="Aparecen solas al cargar una autoevaluación que tenga una hoja de fotos: FOTOS, FOTO, EVIDENCIAS, EVIDENCIA o FOTOGRAFÍAS."
  >
    <template #accion>
      <AppButton icono="upload" @click="router.push({ name: 'cargar-auditoria' })">Cargar auditoría</AppButton>
    </template>
  </EmptyState>

  <div v-else class="mx-auto max-w-6xl space-y-6 p-4 pb-10 sm:p-6">
    <div class="flex flex-wrap items-center gap-2">
      <Chip :selected="sedeElegida === null" @click="sedeElegida = null">
        Todas · {{ Formato.numero(evidenciasQuery.data.value?.total ?? 0) }}
      </Chip>
      <Chip v-for="sede in sedes" :key="sede.id" :selected="sedeElegida === sede.id" @click="sedeElegida = sede.id">
        {{ sede.nombre }} · {{ sede.fotos }}
      </Chip>
    </div>

    <p class="text-[12.5px] text-(--color-apagado)">
      {{ Formato.numero(totalVisible) }} {{ totalVisible === 1 ? 'foto' : 'fotos' }} de
      {{ grupos.length }} {{ grupos.length === 1 ? 'auditoría' : 'auditorías' }}. Haga clic en una foto para ampliarla.
    </p>

    <section v-for="grupo in grupos" :key="grupo.auditoria.id">
      <div class="mb-3 flex flex-wrap items-center gap-x-3 gap-y-1.5 border-b border-(--color-regla) pb-2">
        <h2 class="text-[15px] font-semibold">{{ grupo.auditoria.sede?.nombre ?? 'Sede sin nombre' }}</h2>
        <StatusBadge :texto="etiquetaAuditoria(grupo.auditoria)" color="var(--color-sello)" fondo="var(--color-sello-suave)" />
        <StatusBadge
          v-if="grupo.auditoria.estado !== 'publicada'"
          texto="Por confirmar"
          color="var(--color-alerta)"
          fondo="var(--color-alerta-fondo)"
        />
        <span class="ml-auto text-[12px] text-(--color-apagado)">
          {{ grupo.fotos.length }} {{ grupo.fotos.length === 1 ? 'foto' : 'fotos' }}
        </span>
      </div>

      <div class="grid grid-cols-2 gap-2.5 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
        <button
          v-for="foto in grupo.fotos"
          :key="foto.id"
          type="button"
          class="group relative aspect-square overflow-hidden rounded border border-(--color-regla) bg-(--color-hundido) focus-visible:outline-2 focus-visible:outline-(--color-sello)"
          :title="ubicacion(foto)"
          @click="abrir(foto)"
        >
          <img
            :src="foto.miniaturaUrl"
            :alt="`Foto ${foto.orden} de ${grupo.auditoria.sede?.nombre ?? 'la sede'}`"
            loading="lazy"
            decoding="async"
            class="h-full w-full object-cover transition-transform duration-200 group-hover:scale-[1.03]"
          />
          <span
            class="absolute inset-x-0 bottom-0 bg-linear-to-t from-black/60 to-transparent px-2 pt-4 pb-1.5 text-left text-[11px] text-white opacity-0 transition-opacity group-hover:opacity-100 group-focus-visible:opacity-100"
          >
            {{ foto.hoja }}{{ foto.celda ? ` · ${foto.celda}` : '' }}
          </span>
        </button>
      </div>
    </section>
  </div>

  <!-- Visor -->
  <Teleport to="body">
    <Transition
      enter-active-class="transition-opacity duration-150"
      leave-active-class="transition-opacity duration-150"
      enter-from-class="opacity-0"
      leave-to-class="opacity-0"
    >
      <div
        v-if="actual"
        class="fixed inset-0 z-50 flex flex-col bg-black/90 text-white"
        role="dialog"
        aria-modal="true"
        :aria-label="`Foto ${actual.posicion} de ${actual.deGrupo}`"
        @click.self="cerrar"
      >
        <div class="flex items-center gap-3 px-4 py-3">
          <div class="min-w-0 flex-1">
            <p class="truncate text-[14px] font-semibold">{{ actual.auditoria.sede?.nombre }}</p>
            <p class="truncate text-[12px] text-white/70">
              {{ etiquetaAuditoria(actual.auditoria) }} · {{ ubicacion(actual.foto) }} · foto {{ actual.posicion }} de
              {{ actual.deGrupo }}
            </p>
          </div>
          <a
            :href="actual.foto.imagenUrl"
            :download="nombreDescarga(actual.foto, actual.auditoria)"
            class="flex h-10 w-10 items-center justify-center rounded-full hover:bg-white/15"
            title="Descargar"
          >
            <Icon name="download" :size="20" />
          </a>
          <button
            type="button"
            class="flex h-10 w-10 items-center justify-center rounded-full hover:bg-white/15"
            title="Cerrar (Esc)"
            @click="cerrar"
          >
            <Icon name="close" :size="22" />
          </button>
        </div>

        <div class="relative flex min-h-0 flex-1 items-center justify-center px-14 pb-6" @click.self="cerrar">
          <svg v-if="cargandoImagen" class="absolute h-7 w-7 animate-spin text-white/70" viewBox="0 0 24 24" fill="none">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" />
            <path class="opacity-80" fill="currentColor" d="M12 2a10 10 0 0 1 10 10h-3a7 7 0 0 0-7-7z" />
          </svg>
          <img
            :key="actual.foto.id"
            :src="actual.foto.imagenUrl"
            :alt="`Foto ${actual.posicion} de ${actual.auditoria.sede?.nombre ?? 'la sede'}`"
            class="max-h-full max-w-full rounded object-contain shadow-2xl transition-opacity"
            :class="cargandoImagen ? 'opacity-0' : 'opacity-100'"
            @load="cargandoImagen = false"
            @error="cargandoImagen = false"
          />

          <button
            v-if="secuencia.length > 1"
            type="button"
            class="absolute left-2 flex h-11 w-11 items-center justify-center rounded-full bg-white/10 hover:bg-white/25"
            title="Anterior (←)"
            @click="mover(-1)"
          >
            <Icon name="chevron-left" :size="24" />
          </button>
          <button
            v-if="secuencia.length > 1"
            type="button"
            class="absolute right-2 flex h-11 w-11 items-center justify-center rounded-full bg-white/10 hover:bg-white/25"
            title="Siguiente (→)"
            @click="mover(1)"
          >
            <Icon name="chevron-right" :size="24" />
          </button>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>
