/** Formatos en español de Colombia: coma decimal y punto de miles. Calcado
 * de app/lib/nucleo/tema.dart (clase Formato). */
const formatoEntero = new Intl.NumberFormat('es-CO', { maximumFractionDigits: 0 })
const formatoPorcentaje = new Intl.NumberFormat('es-CO', { minimumFractionDigits: 1, maximumFractionDigits: 1 })

const MESES = [
  'enero',
  'febrero',
  'marzo',
  'abril',
  'mayo',
  'junio',
  'julio',
  'agosto',
  'septiembre',
  'octubre',
  'noviembre',
  'diciembre',
]

export const Formato = {
  numero(valor: number): string {
    return formatoEntero.format(valor)
  },

  porcentaje(valor: number | null | undefined): string {
    if (valor === null || valor === undefined) return '—'
    return `${formatoPorcentaje.format(valor * 100)} %`
  },

  periodo(aaaaMm: string): string {
    const partes = aaaaMm.split('-')
    if (partes.length !== 2) return aaaaMm

    const mes = Number.parseInt(partes[1], 10)
    if (Number.isNaN(mes) || mes < 1 || mes > 12) return aaaaMm

    return `${MESES[mes - 1]} de ${partes[0]}`
  },
}
