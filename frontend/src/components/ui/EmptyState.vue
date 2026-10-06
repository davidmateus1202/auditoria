<script setup lang="ts">
import Icon from './Icon.vue'

/** Estado vacío que explica qué falta y cómo llenarlo, en vez de una
 * pantalla en blanco que no dice nada. Mirror de SinContenido
 * (widgets/comunes.dart). */
withDefaults(defineProps<{ titulo: string; detalle: string; icono?: string }>(), { icono: 'inbox' })
</script>

<template>
  <div class="flex flex-col items-center px-8 py-12 text-center">
    <Icon :name="icono" :size="42" class="text-(--color-regla)" />
    <p class="mt-4 text-base font-semibold">{{ titulo }}</p>
    <p class="mt-2 max-w-sm text-[13.5px] leading-relaxed text-(--color-apagado)">{{ detalle }}</p>
    <div v-if="$slots.accion" class="mt-5">
      <slot name="accion" />
    </div>
  </div>
</template>
