<script setup lang="ts">
import { useRouter } from 'vue-router'

import { confirmar } from '@/composables/useDialogs'
import { useSessionStore } from '@/stores/session.store'
import Icon from '../ui/Icon.vue'

/** Contenido de navegación compartido entre el sidebar de escritorio y el
 * drawer móvil. Mirror de _Menu (app/lib/main.dart). */
const emit = defineEmits<{ navegar: [] }>()

const session = useSessionStore()
const router = useRouter()

// Inicio es la ruta hija vacía de AppShell: vue-router la da por activa en
// todas las pantallas, así que solo se resalta con coincidencia exacta.
const principales = [
  { a: 'dashboard', icono: 'home', texto: 'Inicio', exacto: true },
  { a: 'hallazgos', icono: 'warning', texto: 'Hallazgos' },
  { a: 'corte', icono: 'calendar', texto: 'Corte' },
  { a: 'consolidado', icono: 'table', texto: 'Consolidado' },
  { a: 'evidencias', icono: 'image', texto: 'Evidencias' },
]

const secundarios = [
  {
    a: 'cargar-auditoria',
    icono: 'upload',
    texto: 'Cargar auditoría',
    detalle: 'Autoevaluaciones SUH y archivos cargados',
  },
]

async function salir() {
  const ok = await confirmar({
    titulo: '¿Cerrar sesión?',
    mensaje: 'Tendrá que volver a ingresar su correo y contraseña.',
    textoConfirmar: 'Cerrar sesión',
    tono: 'critico',
  })
  if (!ok) return

  await session.salir()
  emit('navegar')
  router.push({ name: 'login' })
}
</script>

<template>
  <div class="flex h-full flex-col">
    <div class="p-5">
      <p class="text-[11px] font-bold tracking-[0.1em] text-(--color-sello)">AUDITORÍA SUH</p>
      <p class="mt-1 text-[16px] font-semibold">{{ session.usuario?.nombre ?? '' }}</p>
      <p class="text-[12.5px] text-(--color-apagado)">{{ session.usuario?.email ?? '' }}</p>
    </div>
    <div class="h-px bg-(--color-regla)" />

    <nav class="flex flex-col gap-0.5 p-2.5">
      <RouterLink
        v-for="item in principales"
        :key="item.a"
        :to="{ name: item.a }"
        class="flex items-center gap-3 rounded px-3 py-2.5 text-[14px] font-medium text-(--color-tinta-2) hover:bg-(--color-hundido)"
        :active-class="item.exacto ? '' : '!bg-(--color-sello-suave) !text-(--color-sello)'"
        :exact-active-class="item.exacto ? '!bg-(--color-sello-suave) !text-(--color-sello)' : ''"
        @click="emit('navegar')"
      >
        <Icon :name="item.icono" :size="18" />
        {{ item.texto }}
      </RouterLink>
    </nav>

    <div class="mx-2.5 h-px bg-(--color-regla)" />

    <nav class="flex flex-col gap-0.5 p-2.5">
      <RouterLink
        v-for="item in secundarios"
        :key="item.a"
        :to="{ name: item.a }"
        class="flex items-center gap-3 rounded px-3 py-2.5 text-(--color-tinta-2) hover:bg-(--color-hundido)"
        active-class="!bg-(--color-sello-suave) !text-(--color-sello)"
        @click="emit('navegar')"
      >
        <Icon :name="item.icono" :size="18" class="mt-0.5 shrink-0" />
        <span class="flex flex-col">
          <span class="text-[14px] font-medium">{{ item.texto }}</span>
          <span class="text-[11px] text-(--color-apagado)">{{ item.detalle }}</span>
        </span>
      </RouterLink>
    </nav>

    <div class="flex-1" />

    <div class="h-px bg-(--color-regla)" />
    <p class="p-5 text-[11.5px] leading-relaxed text-(--color-apagado)">
      Resolución 3100 de 2019<br />
      E.S.E. Municipal · Villavicencio, Meta
    </p>
    <button
      type="button"
      class="flex items-center gap-3 px-5 py-3.5 text-left text-[14px] text-(--color-tinta-2) hover:bg-(--color-hundido)"
      @click="salir"
    >
      <Icon name="logout" :size="18" />
      Cerrar sesión
    </button>
  </div>
</template>
