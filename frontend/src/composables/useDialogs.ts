import { ref } from 'vue'

/**
 * Reemplaza los `showDialog<bool>`/`showDialog<String>` de la app Flutter:
 * un diálogo modal como una promesa, en vez de un componente que cada
 * pantalla tiene que montar y cablear a mano.
 */
interface EstadoConfirmar {
  tipo: 'confirmar'
  titulo: string
  mensaje: string
  textoConfirmar: string
  textoCancelar: string
  tono: 'sello' | 'critico'
}

interface EstadoPreguntar {
  tipo: 'preguntar'
  titulo: string
  mensaje?: string
  label: string
  placeholder?: string
  valorInicial: string
  multilinea: boolean
  textoConfirmar: string
}

export const estadoDialogo = ref<EstadoConfirmar | EstadoPreguntar | null>(null)

let resolver: ((valor: boolean | string | null) => void) | null = null

export function confirmar(opciones: {
  titulo: string
  mensaje: string
  textoConfirmar?: string
  textoCancelar?: string
  tono?: 'sello' | 'critico'
}): Promise<boolean> {
  estadoDialogo.value = {
    tipo: 'confirmar',
    titulo: opciones.titulo,
    mensaje: opciones.mensaje,
    textoConfirmar: opciones.textoConfirmar ?? 'Confirmar',
    textoCancelar: opciones.textoCancelar ?? 'Cancelar',
    tono: opciones.tono ?? 'sello',
  }

  return new Promise((resolve) => {
    resolver = (valor) => resolve(valor === true)
  })
}

export function preguntar(opciones: {
  titulo: string
  mensaje?: string
  label: string
  placeholder?: string
  valorInicial?: string
  multilinea?: boolean
  textoConfirmar?: string
}): Promise<string | null> {
  estadoDialogo.value = {
    tipo: 'preguntar',
    titulo: opciones.titulo,
    mensaje: opciones.mensaje,
    label: opciones.label,
    placeholder: opciones.placeholder,
    valorInicial: opciones.valorInicial ?? '',
    multilinea: opciones.multilinea ?? false,
    textoConfirmar: opciones.textoConfirmar ?? 'Aceptar',
  }

  return new Promise((resolve) => {
    resolver = (valor) => resolve(typeof valor === 'string' ? valor : null)
  })
}

export function resolverDialogo(valor: boolean | string | null): void {
  estadoDialogo.value = null
  resolver?.(valor)
  resolver = null
}
