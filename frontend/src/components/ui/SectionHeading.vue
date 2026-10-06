<script setup lang="ts">
/** Encabezado de sección con la regla que lo cierra. Mirror de
 * RotuloSeccion (widgets/comunes.dart). */
defineProps<{ texto: string }>()
</script>

<template>
  <div class="mb-2.5 flex items-center gap-2.5">
    <span class="text-[11px] font-bold tracking-[0.08em] text-(--color-sello) uppercase">{{ texto }}</span>
    <span class="h-px flex-1 bg-(--color-regla)" />
    <slot name="accion" />
  </div>
</template>
