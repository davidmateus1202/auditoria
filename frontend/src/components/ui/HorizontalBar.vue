<script setup lang="ts">
import { computed } from 'vue'

import { Formato } from '@/core/format'

/** Barra horizontal con rótulo y valor: el gráfico que más se usa aquí,
 * porque los estándares tienen nombres largos y una barra vertical los
 * recortaría. Mirror de BarraHorizontal (widgets/comunes.dart). */
const props = withDefaults(
  defineProps<{
    rotulo: string
    valor: number
    maximo: number
    porcentaje?: number | null
    color?: string
    clicable?: boolean
  }>(),
  { porcentaje: null, color: 'var(--color-sello)', clicable: false },
)

const emit = defineEmits<{ click: [] }>()

const fraccion = computed(() => (props.maximo === 0 ? 0 : Math.min(1, props.valor / props.maximo)))
</script>

<template>
  <component
    :is="clicable ? 'button' : 'div'"
    type="button"
    class="flex w-full items-center gap-2.5 rounded py-1.5"
    :class="clicable ? 'cursor-pointer text-left hover:bg-(--color-hundido)/60' : ''"
    @click="clicable && emit('click')"
  >
    <span class="line-clamp-2 w-32 shrink-0 text-[12.5px] leading-tight text-(--color-tinta-2)">{{ rotulo }}</span>
    <span class="h-4 flex-1 overflow-hidden rounded-[2px] border border-(--color-regla) bg-(--color-hundido)">
      <span
        class="block h-full rounded-[1px]"
        :style="{ width: `${fraccion * 100}%`, backgroundColor: color }"
      />
    </span>
    <span class="tabular w-10 shrink-0 text-right text-[13px] font-bold">{{ Formato.numero(valor) }}</span>
    <span v-if="porcentaje !== null" class="tabular w-13 shrink-0 text-right text-[11.5px] text-(--color-apagado)">
      {{ Formato.porcentaje(porcentaje) }}
    </span>
  </component>
</template>
