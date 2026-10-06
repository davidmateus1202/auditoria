<script setup lang="ts">
import { ref, watch } from 'vue'

import { estadoDialogo, resolverDialogo } from '@/composables/useDialogs'
import AppButton from './AppButton.vue'
import AppInput from './AppInput.vue'
import AppTextarea from './AppTextarea.vue'
import Modal from './Modal.vue'

const valor = ref('')

watch(estadoDialogo, (nuevo) => {
  if (nuevo?.tipo === 'preguntar') valor.value = nuevo.valorInicial
})

function cerrar() {
  resolverDialogo(estadoDialogo.value?.tipo === 'confirmar' ? false : null)
}
</script>

<template>
  <Modal
    :model-value="estadoDialogo !== null"
    :titulo="estadoDialogo?.titulo"
    max-width="max-w-sm"
    @update:model-value="cerrar"
  >
    <template v-if="estadoDialogo?.tipo === 'confirmar'">
      <p class="text-[13.5px] leading-relaxed text-(--color-tinta-2)">{{ estadoDialogo.mensaje }}</p>
    </template>
    <template v-else-if="estadoDialogo?.tipo === 'preguntar'">
      <p v-if="estadoDialogo.mensaje" class="mb-3 text-[13px] text-(--color-apagado)">{{ estadoDialogo.mensaje }}</p>
      <AppTextarea
        v-if="estadoDialogo.multilinea"
        v-model="valor"
        :label="estadoDialogo.label"
        :placeholder="estadoDialogo.placeholder"
        :rows="3"
      />
      <AppInput v-else v-model="valor" :label="estadoDialogo.label" :placeholder="estadoDialogo.placeholder" />
    </template>

    <template #footer>
      <div class="flex justify-end gap-2">
        <AppButton variante="text" tono="tinta" @click="cerrar">
          {{ estadoDialogo?.tipo === 'confirmar' ? estadoDialogo.textoCancelar : 'Cancelar' }}
        </AppButton>
        <AppButton
          :tono="estadoDialogo?.tipo === 'confirmar' ? estadoDialogo.tono : 'sello'"
          @click="resolverDialogo(estadoDialogo?.tipo === 'confirmar' ? true : valor.trim())"
        >
          {{ estadoDialogo?.tipo === 'confirmar' ? estadoDialogo.textoConfirmar : estadoDialogo?.textoConfirmar }}
        </AppButton>
      </div>
    </template>
  </Modal>
</template>
