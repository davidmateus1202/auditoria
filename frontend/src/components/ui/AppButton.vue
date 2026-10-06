<script setup lang="ts">
import Icon from './Icon.vue'

/** Botón de acción. Tres variantes que calcan los `ButtonThemeData` de
 * app/lib/nucleo/tema.dart: filled (acción principal), outlined (secundaria)
 * y text (terciaria, dentro de avisos/tarjetas). */
withDefaults(
  defineProps<{
    variante?: 'filled' | 'outlined' | 'text'
    icono?: string
    cargando?: boolean
    disabled?: boolean
    tono?: 'sello' | 'critico' | 'tinta'
    type?: 'button' | 'submit'
    block?: boolean
  }>(),
  {
    variante: 'filled',
    icono: undefined,
    cargando: false,
    disabled: false,
    tono: 'sello',
    type: 'button',
    block: false,
  },
)

const colores: Record<string, string> = {
  sello: 'var(--color-sello)',
  critico: 'var(--color-critico)',
  tinta: 'var(--color-tinta-2)',
}
</script>

<template>
  <button
    :type="type"
    :disabled="disabled || cargando"
    class="inline-flex items-center justify-center gap-2 rounded text-[15px] font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-60"
    :class="[
      block ? 'w-full' : '',
      variante === 'filled' ? 'px-5 py-3.5 text-white' : '',
      variante === 'outlined' ? 'border px-5 py-3.5' : '',
      variante === 'text' ? 'px-2 py-1.5' : '',
    ]"
    :style="
      variante === 'filled'
        ? { backgroundColor: colores[tono] }
        : variante === 'outlined'
          ? { color: colores[tono], borderColor: 'var(--color-regla)' }
          : { color: colores[tono] }
    "
  >
    <svg
      v-if="cargando"
      class="h-4 w-4 animate-spin"
      :class="variante === 'filled' ? 'text-white' : ''"
      viewBox="0 0 24 24"
      fill="none"
    >
      <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" />
      <path class="opacity-80" fill="currentColor" d="M12 2a10 10 0 0 1 10 10h-3a7 7 0 0 0-7-7z" />
    </svg>
    <Icon v-else-if="icono" :name="icono" :size="18" />
    <slot />
  </button>
</template>
