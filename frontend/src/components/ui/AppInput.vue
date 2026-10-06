<script setup lang="ts">
import Icon from './Icon.vue'

/** Campo de texto. Mirror visual del InputDecorationTheme de
 * app/lib/nucleo/tema.dart (borde gris, foco en azul sello). */
withDefaults(
  defineProps<{
    modelValue: string
    label?: string
    type?: string
    icono?: string
    error?: string
    placeholder?: string
    autocomplete?: string
  }>(),
  {
    label: undefined,
    type: 'text',
    icono: undefined,
    error: undefined,
    placeholder: undefined,
    autocomplete: undefined,
  },
)

defineEmits<{ 'update:modelValue': [string] }>()
</script>

<template>
  <label class="block">
    <span v-if="label" class="mb-1.5 block text-[13px] font-medium text-(--color-tinta-2)">{{ label }}</span>
    <span
      class="flex items-center gap-2 rounded border bg-(--color-superficie) px-3.5 focus-within:border-(--color-sello) focus-within:ring-1 focus-within:ring-(--color-sello)"
      :class="error ? 'border-(--color-critico)' : 'border-(--color-regla)'"
    >
      <Icon v-if="icono" :name="icono" :size="18" class="text-(--color-apagado)" />
      <input
        :type="type"
        :value="modelValue"
        :placeholder="placeholder"
        :autocomplete="autocomplete"
        class="w-full bg-transparent py-3.5 text-[15px] outline-none"
        @input="$emit('update:modelValue', ($event.target as HTMLInputElement).value)"
      />
      <slot name="suffix" />
    </span>
    <span v-if="error" class="mt-1.5 block text-[12.5px] text-(--color-critico)">{{ error }}</span>
  </label>
</template>
