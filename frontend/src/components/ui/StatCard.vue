<script setup lang="ts">
/** Cifra grande con su rótulo. Se usa solo cuando el número ES el punto de
 * la pantalla; en el resto la información va en tablas. Mirror de Cifra
 * (widgets/comunes.dart). */
withDefaults(
  defineProps<{
    valor: string
    rotulo: string
    color?: string
    detalle?: string
  }>(),
  { color: undefined, detalle: undefined },
)
</script>

<template>
  <div class="flex min-w-0 flex-col items-start">
    <span
      class="tabular text-[28px] leading-none font-bold tracking-tight sm:text-[30px]"
      :style="{ color: color ?? 'var(--color-tinta)' }"
    >
      {{ valor }}
    </span>
    <span
      class="mt-1.5 text-[10.5px] leading-tight font-semibold tracking-wide whitespace-pre-line text-(--color-apagado) uppercase"
    >
      {{ rotulo }}
    </span>
    <span v-if="detalle" class="mt-0.5 text-[11.5px] text-(--color-tinta-2)">{{ detalle }}</span>
  </div>
</template>
