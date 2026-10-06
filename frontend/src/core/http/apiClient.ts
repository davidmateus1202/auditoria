import axios, { AxiosError, type AxiosInstance } from 'axios'

import { sesion } from './session'

/**
 * Error de API con el mensaje que se le puede mostrar a una persona.
 *
 * El backend rechaza los archivos que no tienen la estructura esperada
 * indicando hoja y fila; esa información tiene que llegar entera a la
 * pantalla, no convertirse en un «error inesperado». Calcado de
 * app/lib/nucleo/api.dart.
 */
export class ApiError extends Error {
  readonly codigo?: number
  readonly detalles?: Record<string, unknown>

  constructor(mensaje: string, codigo?: number, detalles?: Record<string, unknown>) {
    super(mensaje)
    this.name = 'ApiError'
    this.codigo = codigo
    this.detalles = detalles
  }

  get mensaje(): string {
    return this.message
  }

  get esAutenticacion(): boolean {
    return this.codigo === 401
  }
}

function traducir(error: AxiosError): ApiError {
  const respuesta = error.response

  if (!respuesta) {
    const mensaje =
      error.code === 'ECONNABORTED'
        ? 'El servidor tardó demasiado en responder. Intente de nuevo.'
        : error.message === 'Network Error'
          ? 'No hay conexión con el servidor. Revise la red e intente de nuevo.'
          : 'No se pudo completar la operación.'
    return new ApiError(mensaje)
  }

  const cuerpo =
    respuesta.data && typeof respuesta.data === 'object' ? (respuesta.data as Record<string, unknown>) : {}

  // Errores de validación de Laravel: se muestra el primero, que es el que
  // la persona tiene que corregir.
  if (respuesta.status === 422 && cuerpo.errors && typeof cuerpo.errors === 'object') {
    const errores = Object.values(cuerpo.errors as Record<string, unknown>)
    const primero = errores.length === 0 ? null : (errores[0] as unknown[])[0]
    return new ApiError(
      primero == null ? 'Los datos enviados no son válidos.' : String(primero),
      422,
      cuerpo,
    )
  }

  const mensajePorEstado: Record<number, string> = {
    401: 'La sesión expiró. Vuelva a ingresar.',
    403: 'No tiene permiso para hacer esto.',
    404: 'No se encontró lo que se buscaba.',
  }

  const mensaje =
    (cuerpo.mensaje as string | undefined) ??
    (cuerpo.message as string | undefined) ??
    mensajePorEstado[respuesta.status] ??
    'El servidor respondió con un error.'

  // Un token viejo no debería dejar la app atascada en una pantalla de
  // error: se avisa para que la sesión se limpie y vuelva al ingreso.
  if (respuesta.status === 401) {
    window.dispatchEvent(new CustomEvent('auth:unauthorized'))
  }

  return new ApiError(mensaje, respuesta.status, cuerpo)
}

export const baseUrl = (import.meta.env.VITE_API_URL as string | undefined) || '/api'

export const http: AxiosInstance = axios.create({
  baseURL: baseUrl,
  // La generación del Excel puede tardar en un consolidado grande.
  timeout: 90_000,
  headers: { Accept: 'application/json' },
})

http.interceptors.request.use((config) => {
  const token = sesion.token()
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

/** Toda petición que no use esta función directamente debe envolver sus
 * errores con `traducir` para que la pantalla reciba un ApiError y no una
 * AxiosError envuelta. */
export { traducir }

http.interceptors.response.use(
  (respuesta) => respuesta,
  (error: AxiosError) => Promise.reject(traducir(error)),
)

export async function obtener<T = Record<string, unknown>>(
  ruta: string,
  parametros?: Record<string, unknown>,
): Promise<T> {
  const r = await http.get<T>(ruta, { params: parametros })
  return r.data
}

export async function enviar<T = Record<string, unknown>>(ruta: string, cuerpo?: unknown): Promise<T> {
  const r = await http.post<T>(ruta, cuerpo)
  return r.data
}

export async function actualizar<T = Record<string, unknown>>(ruta: string, cuerpo?: unknown): Promise<T> {
  const r = await http.put<T>(ruta, cuerpo)
  return r.data
}

export async function eliminar<T = Record<string, unknown>>(ruta: string): Promise<T> {
  const r = await http.delete<T>(ruta)
  return r.data
}

export async function descargar(ruta: string, cuerpo?: unknown): Promise<Blob> {
  const r = await http.request<Blob>({
    url: ruta,
    method: cuerpo === undefined ? 'GET' : 'POST',
    data: cuerpo,
    responseType: 'blob',
  })
  return r.data
}
