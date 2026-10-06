<script setup lang="ts">
import { ref } from 'vue'

import Icon from '../ui/Icon.vue'
import SidebarNav from './SidebarNav.vue'

/**
 * Navegación principal. Sidebar persistente en escritorio; en móvil, barra
 * inferior con los cuatro destinos de siempre (igual que el NavigationBar
 * de Flutter) más un menú superior para lo secundario (cargar auditoría,
 * archivos cargados, cerrar sesión) — el equivalente del Drawer.
 */
const menuAbierto = ref(false)

const destinosMoviles = [
  { a: 'dashboard', icono: 'home', texto: 'Inicio' },
  { a: 'hallazgos', icono: 'warning', texto: 'Hallazgos' },
  { a: 'corte', icono: 'calendar', texto: 'Corte' },
  { a: 'consolidado', icono: 'table', texto: 'Consolidado' },
]
</script>

<template>
  <div class="flex h-screen overflow-hidden bg-(--color-papel)">
    <aside class="hidden w-64 shrink-0 border-r border-(--color-regla) bg-(--color-superficie) lg:flex">
      <SidebarNav />
    </aside>

    <Teleport to="body">
      <Transition
        enter-active-class="transition-opacity duration-150"
        leave-active-class="transition-opacity duration-150"
        enter-from-class="opacity-0"
        leave-to-class="opacity-0"
      >
        <div v-if="menuAbierto" class="fixed inset-0 z-40 lg:hidden">
          <div class="absolute inset-0 bg-black/40" @click="menuAbierto = false" />
          <Transition
            enter-active-class="transition-transform duration-200"
            leave-active-class="transition-transform duration-150"
            enter-from-class="-translate-x-full"
            leave-to-class="-translate-x-full"
            appear
          >
            <aside class="absolute inset-y-0 left-0 w-72 bg-(--color-superficie) shadow-xl">
              <SidebarNav @navegar="menuAbierto = false" />
            </aside>
          </Transition>
        </div>
      </Transition>
    </Teleport>

    <div class="flex min-w-0 flex-1 flex-col overflow-hidden">
      <div class="flex items-center gap-3 border-b border-(--color-regla) bg-(--color-superficie) px-4 py-3 lg:hidden">
        <button type="button" class="text-(--color-tinta)" @click="menuAbierto = true">
          <Icon name="menu" :size="22" />
        </button>
        <span class="text-[15px] font-bold tracking-tight">Auditoría SUH</span>
      </div>

      <main class="flex-1 overflow-y-auto">
        <RouterView />
      </main>

      <nav class="grid grid-cols-4 border-t border-(--color-regla) bg-(--color-superficie) pb-[env(safe-area-inset-bottom)] lg:hidden">
        <RouterLink
          v-for="destino in destinosMoviles"
          :key="destino.a"
          :to="{ name: destino.a }"
          class="flex flex-col items-center gap-0.5 py-2.5 text-[11px] text-(--color-apagado)"
          active-class="!text-(--color-sello)"
        >
          <Icon :name="destino.icono" :size="21" />
          {{ destino.texto }}
        </RouterLink>
      </nav>
    </div>
  </div>
</template>
