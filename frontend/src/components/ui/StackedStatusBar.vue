<script setup lang="ts">
import { computed } from 'vue'

import { generalesPorEstado, type Generales } from '@/domain/models'

/** Barra apilada del reparto de estados. Cuatro segmentos, sin leyenda
 * propia: la leyenda va aparte para que la barra se pueda repetir en una
 * lista. Mirror de BarraEstados (widgets/comunes.dart). */
const props = withDefaults(defineProps<{ generales: Generales; altura?: number }>(), { altura: 10 })

const segmentos = computed(() =>
  generalesPorEstado(props.generales).filter(([, valor]) => valor > 0),
)
</script>

<template>
  <div
    v-if="generales.hallazgos === 0"
    class="rounded-[2px] bg-(--color-hundido)"
    :style="{ height: `${altura}px` }"
  />
  <div v-else class="flex overflow-hidden rounded-[2px]" :style="{ height: `${altura}px` }">
    <div
      v-for="[estado, valor] in segmentos"
      :key="estado.valor"
      :style="{ backgroundColor: estado.color, flexGrow: valor, flexBasis: 0 }"
    />
  </div>
</template>
