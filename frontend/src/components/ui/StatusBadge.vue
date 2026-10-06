<script setup lang="ts">
/** Etiqueta de estado. Codifica el estado en forma y color, no solo en
 * texto: en una lista larga hay que poder ver qué pide atención sin leer.
 * Mirror de Pastilla (widgets/comunes.dart). */
withDefaults(
  defineProps<{
    texto: string
    color: string
    fondo?: string
  }>(),
  { fondo: undefined },
)
</script>

<template>
  <span
    class="inline-flex items-center rounded-sm px-2 py-0.5 text-[10.5px] font-bold uppercase tracking-wide"
    :style="{ color, backgroundColor: fondo ?? `${color}1f` }"
  >
    {{ texto }}
  </span>
</template>
