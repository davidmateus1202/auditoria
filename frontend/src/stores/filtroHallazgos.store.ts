import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

import type { EstadoHallazgoValor } from '@/domain/enums'

/** Estado de los filtros. Sede, estándar y sede+estándar son los tres cortes
 * que pidió el usuario: aquí son el mismo listado con distinta combinación.
 * Mirror de FiltroHallazgos (app/lib/pantallas/hallazgos.dart). */
export const useFiltroHallazgosStore = defineStore('filtroHallazgos', () => {
  const sedeId = ref<number | null>(null)
  const sedeCodigo = ref<string | null>(null)
  const estandar = ref<string | null>(null)
  const estado = ref<EstadoHallazgoValor | null>(null)
  const buscar = ref('')
  const soloVigentes = ref(true)

  const activos = computed(
    () => [sedeId.value !== null, estandar.value !== null, estado.value !== null, buscar.value !== ''].filter(
      Boolean,
    ).length,
  )

  function establecerSede(id: number | null, codigo: string | null) {
    sedeId.value = id
    sedeCodigo.value = codigo
  }

  function reiniciar() {
    sedeId.value = null
    sedeCodigo.value = null
    estandar.value = null
    estado.value = null
    buscar.value = ''
    soloVigentes.value = true
  }

  return { sedeId, sedeCodigo, estandar, estado, buscar, soloVigentes, activos, establecerSede, reiniciar }
})
