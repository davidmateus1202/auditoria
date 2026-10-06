<script setup lang="ts">
import Icon from './Icon.vue'

/** Chip de selección única o de filtro booleano. Mirror visual de
 * ChoiceChip/FilterChip/ActionChip (ChipThemeData en tema.dart). */
withDefaults(
  defineProps<{
    selected?: boolean
    icono?: string
    color?: string
  }>(),
  { selected: false, icono: undefined, color: 'var(--color-sello)' },
)

defineEmits<{ click: [] }>()
</script>

<template>
  <button
    type="button"
    class="inline-flex items-center gap-1.5 rounded border px-3 py-1.5 text-[13px] whitespace-nowrap transition-colors"
    :class="selected ? 'font-semibold' : 'font-normal text-(--color-tinta-2)'"
    :style="
      selected
        ? { color, backgroundColor: `${color}14`, borderColor: color }
        : { borderColor: 'var(--color-regla)' }
    "
    @click="$emit('click')"
  >
    <Icon v-if="icono" :name="icono" :size="15" />
    <slot />
  </button>
</template>
