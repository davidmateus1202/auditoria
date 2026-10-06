<script setup lang="ts">
/** Tarjeta con borde, la unidad de composición de casi todas las pantallas.
 * Mirror de Bloque (widgets/comunes.dart). */
withDefaults(defineProps<{ sinPadding?: boolean }>(), { sinPadding: false })
</script>

<template>
  <div
    class="w-full rounded border border-(--color-regla) bg-(--color-superficie)"
    :class="sinPadding ? '' : 'p-4'"
  >
    <slot />
  </div>
</template>
