<script setup lang="ts">
import Icon from './Icon.vue'

/**
 * Diálogo genérico. En móvil sube desde abajo como una hoja (igual que
 * showModalBottomSheet en Flutter); en escritorio aparece centrado como un
 * diálogo convencional. Una sola implementación cubre ambos casos de uso de
 * la app Flutter (bottom sheets y AlertDialog).
 */
withDefaults(
  defineProps<{
    modelValue: boolean
    titulo?: string
    maxWidth?: string
  }>(),
  { titulo: undefined, maxWidth: 'max-w-md' },
)

const emit = defineEmits<{ 'update:modelValue': [boolean] }>()

function cerrar() {
  emit('update:modelValue', false)
}
</script>

<template>
  <Teleport to="body">
    <Transition
      enter-active-class="transition-opacity duration-150"
      leave-active-class="transition-opacity duration-150"
      enter-from-class="opacity-0"
      leave-to-class="opacity-0"
    >
      <div
        v-if="modelValue"
        class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 sm:items-center sm:p-4"
        @click.self="cerrar"
      >
        <Transition
          enter-active-class="transition-transform duration-200"
          leave-active-class="transition-transform duration-150"
          enter-from-class="translate-y-full sm:translate-y-4 sm:opacity-0"
          leave-to-class="translate-y-full sm:translate-y-4 sm:opacity-0"
        >
          <div
            v-if="modelValue"
            class="flex max-h-[90vh] w-full flex-col rounded-t-lg bg-(--color-superficie) shadow-xl sm:rounded-lg"
            :class="maxWidth"
          >
            <div v-if="titulo" class="flex items-center justify-between border-b border-(--color-regla) p-4">
              <h2 class="text-base font-bold">{{ titulo }}</h2>
              <button type="button" class="text-(--color-apagado) hover:text-(--color-tinta)" @click="cerrar">
                <Icon name="close" :size="18" />
              </button>
            </div>
            <div class="min-h-0 flex-1 overflow-y-auto p-5">
              <slot />
            </div>
            <div v-if="$slots.footer" class="border-t border-(--color-regla) p-4">
              <slot name="footer" />
            </div>
          </div>
        </Transition>
      </div>
    </Transition>
  </Teleport>
</template>
