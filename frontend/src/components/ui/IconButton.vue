<script setup lang="ts">
import Icon from './Icon.vue'

withDefaults(
  defineProps<{ icono: string; titulo?: string; tono?: 'normal' | 'critico'; cargando?: boolean }>(),
  { titulo: undefined, tono: 'normal', cargando: false },
)
</script>

<template>
  <button
    type="button"
    :title="titulo"
    :disabled="cargando"
    class="flex h-9 w-9 items-center justify-center rounded-full text-(--color-tinta-2) transition-colors hover:bg-(--color-hundido) disabled:opacity-50"
    :class="tono === 'critico' ? 'text-(--color-critico)' : ''"
  >
    <svg v-if="cargando" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
      <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" />
      <path class="opacity-80" fill="currentColor" d="M12 2a10 10 0 0 1 10 10h-3a7 7 0 0 0-7-7z" />
    </svg>
    <Icon v-else :name="icono" :size="20" />
  </button>
</template>
