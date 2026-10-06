<script setup lang="ts">
/** Cabecera de página. Mirror del AppBar de cada Scaffold en la app
 * Flutter: título a la izquierda, acciones (icon buttons) a la derecha, y
 * un slot `bottom` opcional para una fila extra (filtros, pestañas). */
defineProps<{ titulo: string; conVolver?: boolean }>()
defineEmits<{ volver: [] }>()
</script>

<template>
  <header class="sticky top-0 z-10 border-b border-(--color-regla) bg-(--color-superficie)">
    <div class="flex items-center justify-between gap-3 px-4 py-3.5 sm:px-6">
      <div class="flex min-w-0 items-center gap-2">
        <button
          v-if="conVolver"
          type="button"
          class="-ml-1.5 flex h-9 w-9 items-center justify-center rounded-full text-(--color-tinta-2) hover:bg-(--color-hundido)"
          @click="$emit('volver')"
        >
          <slot name="volver-icono" />
        </button>
        <h1 class="truncate text-[17px] font-semibold tracking-tight">{{ titulo }}</h1>
      </div>
      <div class="flex items-center gap-0.5">
        <slot name="acciones" />
      </div>
    </div>
    <slot name="bottom" />
  </header>
</template>
