<script setup lang="ts">
import { computed, ref } from 'vue'

import Chip from './Chip.vue'
import Icon from './Icon.vue'
import Modal from './Modal.vue'

export interface OpcionSelector {
  valor: string | number
  etiqueta: string
}

/** Selector de una opción entre varias, con "quitar filtro". Mirror de
 * _Selector (pantallas/hallazgos.dart), que en Flutter abre un
 * showModalBottomSheet; aquí usa el Modal genérico. */
const props = defineProps<{
  etiqueta: string
  opciones: OpcionSelector[]
  modelValue: string | number | null
}>()

const emit = defineEmits<{ 'update:modelValue': [string | number | null] }>()

const abierto = ref(false)

const seleccionActual = computed(() => props.opciones.find((o) => o.valor === props.modelValue) ?? null)

function elegir(valor: string | number | null) {
  emit('update:modelValue', valor)
  abierto.value = false
}
</script>

<template>
  <Chip :selected="seleccionActual !== null" :icono="seleccionActual ? 'check' : 'chevron-down'" @click="abierto = true">
    {{ seleccionActual ? seleccionActual.etiqueta : etiqueta }}
  </Chip>

  <Modal v-model="abierto" :titulo="etiqueta" max-width="max-w-sm">
    <div class="flex flex-col">
      <button
        v-if="seleccionActual"
        type="button"
        class="flex items-center gap-2.5 border-b border-(--color-regla) px-1 py-3 text-left text-[14px] text-(--color-tinta-2) hover:text-(--color-tinta)"
        @click="elegir(null)"
      >
        <Icon name="close" :size="17" />
        Quitar este filtro
      </button>
      <button
        v-for="opcion in opciones"
        :key="opcion.valor"
        type="button"
        class="flex items-center justify-between px-1 py-3 text-left text-[14px] hover:bg-(--color-hundido)/50"
        @click="elegir(opcion.valor)"
      >
        <span>{{ opcion.etiqueta }}</span>
        <Icon v-if="opcion.valor === modelValue" name="check" :size="18" class="text-(--color-sello)" />
      </button>
    </div>
  </Modal>
</template>
