<script setup lang="ts">
withDefaults(
  defineProps<{
    modelValue: string
    label?: string
    rows?: number
    placeholder?: string
    helper?: string
    helperTono?: 'normal' | 'alerta'
  }>(),
  { label: undefined, rows: 2, placeholder: undefined, helper: undefined, helperTono: 'normal' },
)

defineEmits<{ 'update:modelValue': [string] }>()
</script>

<template>
  <label class="block">
    <span v-if="label" class="mb-1.5 block text-[13px] font-medium text-(--color-tinta-2)">{{ label }}</span>
    <textarea
      :rows="rows"
      :value="modelValue"
      :placeholder="placeholder"
      class="w-full resize-none rounded border border-(--color-regla) bg-(--color-superficie) px-3.5 py-3 text-[15px] outline-none focus:border-(--color-sello) focus:ring-1 focus:ring-(--color-sello)"
      @input="$emit('update:modelValue', ($event.target as HTMLTextAreaElement).value)"
    />
    <span
      v-if="helper"
      class="mt-1.5 block text-[12px] leading-snug"
      :class="helperTono === 'alerta' ? 'text-(--color-alerta)' : 'text-(--color-apagado)'"
    >
      {{ helper }}
    </span>
  </label>
</template>
