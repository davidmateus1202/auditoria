<script setup lang="ts">
import { generalesPorEstado, type Generales } from '@/domain/models'
import { Formato } from '@/core/format'

/** Mirror de LeyendaEstados (widgets/comunes.dart). */
const props = defineProps<{ generales: Generales }>()
const entradas = generalesPorEstado(props.generales)
</script>

<template>
  <div class="flex flex-wrap items-center gap-x-4.5 gap-y-1.5">
    <div v-for="[estado, valor] in entradas" :key="estado.valor" class="flex items-center gap-1.5">
      <span class="h-2.5 w-2.5 rounded-[2px]" :style="{ backgroundColor: estado.color }" />
      <span class="text-[12.5px] text-(--color-tinta-2)">{{ estado.etiqueta }}</span>
      <span class="tabular text-[12.5px] font-bold">{{ Formato.numero(valor) }}</span>
    </div>
  </div>
</template>
