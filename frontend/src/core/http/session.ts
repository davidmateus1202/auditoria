/**
 * Guarda el token de acceso en localStorage.
 *
 * A diferencia del almacén cifrado de la app móvil, localStorage no tiene un
 * modo "degradado" que falle en runtime; aun así se envuelve en try/catch
 * porque un navegador en modo privado estricto puede negar el acceso, y eso
 * no puede impedir el ingreso: el servidor ya emitió el token.
 */
const CLAVE_TOKEN = 'auditoria_suh_token'

let enMemoria: string | null = null

export const sesion = {
  token(): string | null {
    if (enMemoria !== null) return enMemoria

    try {
      enMemoria = localStorage.getItem(CLAVE_TOKEN)
      return enMemoria
    } catch {
      return null
    }
  },

  guardar(token: string): void {
    enMemoria = token
    try {
      localStorage.setItem(CLAVE_TOKEN, token)
    } catch {
      // Se sigue en memoria: la sesión no sobrevive a recargar, pero funciona.
    }
  },

  borrar(): void {
    enMemoria = null
    try {
      localStorage.removeItem(CLAVE_TOKEN)
    } catch {
      // No hay nada más que hacer.
    }
  },
}
