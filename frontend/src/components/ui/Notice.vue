<script setup lang="ts">
import Icon from './Icon.vue'

/** Aviso con un motivo y, cuando toca, una salida. Mirror de Aviso
 * (widgets/comunes.dart). */
withDefaults(
  defineProps<{
    mensaje: string
    color?: string
    icono?: string
  }>(),
  { color: 'var(--color-sello)', icono: 'info' },
)
</script>

<template>
  <div
    class="flex items-start gap-2.5 rounded-[2px] p-3.5"
    :style="{ backgroundColor: `${color}12`, borderLeft: `3px solid ${color}` }"
  >
    <Icon :name="icono" :size="19" class="mt-0.5 shrink-0" :style="{ color }" />
    <p class="flex-1 text-[13.5px] leading-relaxed text-(--color-tinta-2)">{{ mensaje }}</p>
    <slot name="accion" />
  </div>
</template>
