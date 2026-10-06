import { enviar, obtener, http } from '@/core/http/apiClient'
import { sesion } from '@/core/http/session'
import { usuarioDesdeJson, type Usuario } from '@/domain/models'

export async function ingresar(email: string, clave: string): Promise<Usuario> {
  const r = await enviar<{ token: string; usuario: Record<string, unknown> }>('/auth/login', {
    email,
    password: clave,
  })

  sesion.guardar(r.token)

  return usuarioDesdeJson(r.usuario)
}

export async function yo(): Promise<Usuario> {
  return usuarioDesdeJson(await obtener('/auth/yo'))
}

export async function salir(): Promise<void> {
  try {
    await http.post('/auth/logout')
  } catch {
    // Si el token ya no vale, cerrar sesión localmente es igual de válido.
  }
  sesion.borrar()
}
