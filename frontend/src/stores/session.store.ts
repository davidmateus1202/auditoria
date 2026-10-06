import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

import { ApiError } from '@/core/http/apiClient'
import { sesion } from '@/core/http/session'
import * as authRepository from '@/data/auth.repository'
import type { Usuario } from '@/domain/models'

/** Sesión activa. Mirror de ControladorSesion (app/lib/pantallas/ingreso.dart):
 * `usuario` null + `inicializada` true significa que hay que ingresar. */
export const useSessionStore = defineStore('session', () => {
  const usuario = ref<Usuario | null>(null)
  const inicializada = ref(false)
  const cargando = ref(false)
  let promesaInicio: Promise<void> | null = null

  const autenticado = computed(() => usuario.value !== null)

  async function init(): Promise<void> {
    if (promesaInicio) return promesaInicio

    promesaInicio = (async () => {
      const token = sesion.token()

      if (!token) {
        inicializada.value = true
        return
      }

      try {
        usuario.value = await authRepository.yo()
      } catch (error) {
        // Un token viejo no debería dejar la app atascada en una pantalla de error.
        if (error instanceof ApiError) sesion.borrar()
        usuario.value = null
      } finally {
        inicializada.value = true
      }
    })()

    return promesaInicio
  }

  async function ingresar(email: string, clave: string): Promise<void> {
    cargando.value = true
    try {
      usuario.value = await authRepository.ingresar(email, clave)
    } finally {
      cargando.value = false
    }
  }

  async function salir(): Promise<void> {
    await authRepository.salir()
    usuario.value = null
  }

  // Un 401 en cualquier petición (token expirado o revocado) limpia la
  // sesión y manda de vuelta al ingreso, sin esperar a que la persona
  // navegue a propósito.
  window.addEventListener('auth:unauthorized', () => {
    if (usuario.value === null) return
    sesion.borrar()
    usuario.value = null
    window.location.assign('/login')
  })

  return { usuario, inicializada, cargando, autenticado, init, ingresar, salir }
})
