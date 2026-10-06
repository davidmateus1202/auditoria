<script setup lang="ts">
import { ApiError } from '@/core/http/apiClient'
import EmptyState from './EmptyState.vue'
import AppButton from './AppButton.vue'

/** Pantalla de error con el mensaje real del servidor y un botón de
 * reintentar. Mirror de PantallaError (widgets/comunes.dart). */
const props = defineProps<{ error: unknown; reintentar: () => void }>()

const mensaje = props.error instanceof ApiError ? props.error.mensaje : String(props.error)
</script>

<template>
  <EmptyState icono="error-circle" titulo="No se pudo cargar" :detalle="mensaje">
    <template #accion>
      <AppButton icono="refresh" @click="reintentar">Reintentar</AppButton>
    </template>
  </EmptyState>
</template>
