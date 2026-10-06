<script setup lang="ts">
import { useUiStore } from '@/stores/ui.store'
import Icon from './Icon.vue'

/** Avisos flotantes apilados abajo a la derecha. Mirror de
 * SnackBarThemeData (tema.dart) + ScaffoldMessenger. */
const ui = useUiStore()
</script>

<template>
  <Teleport to="body">
    <div class="pointer-events-none fixed inset-x-0 bottom-4 z-[60] flex flex-col items-center gap-2 px-4 sm:items-end sm:px-6">
      <TransitionGroup
        enter-active-class="transition-all duration-200"
        leave-active-class="transition-all duration-150"
        enter-from-class="opacity-0 translate-y-2"
        leave-to-class="opacity-0"
      >
        <div
          v-for="toast in ui.toasts"
          :key="toast.id"
          class="pointer-events-auto flex max-w-sm items-start gap-2.5 rounded-sm px-4 py-3 text-[13.5px] text-white shadow-lg"
          :style="{ backgroundColor: toast.tipo === 'error' ? 'var(--color-critico)' : 'var(--color-tinta)' }"
        >
          <Icon :name="toast.tipo === 'error' ? 'error-circle' : 'info'" :size="17" class="mt-0.5 shrink-0" />
          <p class="flex-1 leading-snug">{{ toast.mensaje }}</p>
          <button type="button" class="opacity-70 hover:opacity-100" @click="ui.quitar(toast.id)">
            <Icon name="close" :size="14" />
          </button>
        </div>
      </TransitionGroup>
    </div>
  </Teleport>
</template>
