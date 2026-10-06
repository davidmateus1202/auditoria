import { defineStore } from 'pinia'
import { ref } from 'vue'

export interface Toast {
  id: number
  mensaje: string
  tipo: 'info' | 'error'
}

let siguienteId = 1

/** Avisos flotantes. Mirror de ScaffoldMessenger.showSnackBar, usado en casi
 * toda la app Flutter para confirmar una acción o mostrar un error puntual. */
export const useUiStore = defineStore('ui', () => {
  const toasts = ref<Toast[]>([])

  function mostrar(mensaje: string, opciones: { error?: boolean; duracionMs?: number } = {}) {
    const id = siguienteId++
    toasts.value.push({ id, mensaje, tipo: opciones.error ? 'error' : 'info' })

    const duracion = opciones.duracionMs ?? (opciones.error ? 6000 : 4000)
    setTimeout(() => quitar(id), duracion)
  }

  function quitar(id: number) {
    toasts.value = toasts.value.filter((t) => t.id !== id)
  }

  return { toasts, mostrar, quitar }
})
