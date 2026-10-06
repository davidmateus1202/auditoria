<script setup lang="ts">
import { ref } from 'vue'

import Icon from './Icon.vue'

/** Zona de selección de archivos: clic para elegir o arrastrar y soltar.
 * Reemplaza a FilePicker.pickFiles de la app Flutter. */
const props = withDefaults(
  defineProps<{
    accept?: string
    multiple?: boolean
    disabled?: boolean
    texto?: string
  }>(),
  { accept: '.xlsx,.xls', multiple: true, disabled: false, texto: 'Elegir archivos' },
)

const emit = defineEmits<{ files: [File[]] }>()

const input = ref<HTMLInputElement | null>(null)
const arrastrando = ref(false)

function abrir() {
  if (!props.disabled) input.value?.click()
}

function alCambiar(evento: Event) {
  const archivos = Array.from((evento.target as HTMLInputElement).files ?? [])
  if (archivos.length > 0) emit('files', archivos)
  if (input.value) input.value.value = ''
}

function alSoltar(evento: DragEvent) {
  arrastrando.value = false
  if (props.disabled) return
  const archivos = Array.from(evento.dataTransfer?.files ?? [])
  if (archivos.length > 0) emit('files', archivos)
}
</script>

<template>
  <button
    type="button"
    class="flex w-full flex-col items-center gap-2 rounded border-2 border-dashed px-5 py-7 text-center transition-colors"
    :class="[
      disabled ? 'cursor-not-allowed opacity-60' : 'cursor-pointer',
      arrastrando ? 'border-(--color-sello) bg-(--color-sello-suave)/40' : 'border-(--color-regla) bg-(--color-superficie)',
    ]"
    @click="abrir"
    @dragover.prevent="disabled ? null : (arrastrando = true)"
    @dragleave.prevent="arrastrando = false"
    @drop.prevent="alSoltar"
  >
    <Icon name="upload" :size="26" class="text-(--color-sello)" />
    <span class="text-[14px] font-semibold text-(--color-sello)">{{ texto }}</span>
    <span class="text-[12px] text-(--color-apagado)">Arrastre aquí o haga clic para buscar</span>
    <input
      ref="input"
      type="file"
      class="hidden"
      :accept="accept"
      :multiple="multiple"
      :disabled="disabled"
      @change="alCambiar"
    />
  </button>
</template>
