import ExcelJS from 'exceljs'

/**
 * Parsea el Excel fuera del hilo principal.
 *
 * ExcelJS es síncrono y, en un archivo real de varios MB con muchas hojas,
 * tarda diez segundos o más — ejecutado en el hilo principal eso congela la
 * pestaña entera (ni el spinner anima) durante ese rato. Aquí adentro el
 * bloqueo es del worker, no del usuario.
 */

export interface HojaPrevia {
  nombre: string
  filas: string[][]
  truncada: boolean
}

/** `filasMinimas`: por nombre de hoja, hasta qué fila mostrar aunque pase del
 * límite — para que una celda señalada en la fila 450 también se vea. */
export interface MensajeAlWorker {
  buffer: ArrayBuffer
  filasMinimas?: Record<string, number>
}

export type MensajeDesdeWorker =
  | { ok: true; hojas: HojaPrevia[] }
  | { ok: false; error: string }

// El proyecto solo incluye la lib "DOM" en tsconfig (no "WebWorker"), así que
// `self` tipa como `Window`. Este alias local evita arrastrar un segundo
// tsconfig solo para este archivo.
interface ContextoWorker {
  onmessage: ((evento: MessageEvent<MensajeAlWorker>) => void) | null
  postMessage(mensaje: MensajeDesdeWorker): void
}

const contexto = self as unknown as ContextoWorker

const LIMITE_FILAS = 300
const LIMITE_COLUMNAS = 40

function textoDeCelda(valor: ExcelJS.CellValue): string {
  if (valor === null || valor === undefined) return ''
  if (valor instanceof Date) return valor.toLocaleDateString('es-CO')
  if (typeof valor === 'object') {
    if ('richText' in valor) return valor.richText.map((t) => t.text).join('')
    if ('result' in valor) return textoDeCelda(valor.result as ExcelJS.CellValue)
    if ('text' in valor) return String((valor as { text: unknown }).text)
    if ('error' in valor) return `#${(valor as { error: string }).error}`
    return ''
  }
  return String(valor)
}

contexto.onmessage = async (evento) => {
  try {
    const libro = new ExcelJS.Workbook()
    const { buffer, filasMinimas = {} } = evento.data
    await libro.xlsx.load(buffer)

    const hojas: HojaPrevia[] = []

    libro.eachSheet((hoja) => {
      const filas: string[][] = []
      const limite = Math.max(LIMITE_FILAS, filasMinimas[hoja.name] ?? 0)
      const totalFilas = Math.min(hoja.rowCount, limite)

      for (let i = 1; i <= totalFilas; i++) {
        const fila = hoja.getRow(i)
        const totalColumnas = Math.min(hoja.columnCount || fila.cellCount, LIMITE_COLUMNAS)
        const celdas: string[] = []

        for (let c = 1; c <= totalColumnas; c++) {
          const celda = fila.getCell(c)
          // En un bloque combinado ExcelJS repite el valor en cada celda,
          // pero el archivo solo lo guarda en la primera (y es la única que
          // lee el servidor). Repetirlo hacía parecer que corregir A12 no
          // corregía A13.
          const esSecundariaDeCombinada = celda.isMerged && celda.master.address !== celda.address
          celdas.push(esSecundariaDeCombinada ? '' : textoDeCelda(celda.value))
        }

        filas.push(celdas)
      }

      hojas.push({ nombre: hoja.name, filas, truncada: hoja.rowCount > limite })
    })

    contexto.postMessage({ ok: true, hojas })
  } catch (e) {
    contexto.postMessage({ ok: false, error: String(e) })
  }
}
