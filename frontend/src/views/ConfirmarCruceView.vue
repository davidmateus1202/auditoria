<script setup lang="ts">
import { computed, reactive } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import { preguntar } from '@/composables/useDialogs'
import { ApiError } from '@/core/http/apiClient'
import { DestinoReconciliacion, type DestinoReconciliacionValor } from '@/domain/enums'
import { useConfirmarAuditoriaMutation, useReconciliacionQuery } from '@/queries/useAuditorias'
import { useUiStore } from '@/stores/ui.store'
import AppButton from '@/components/ui/AppButton.vue'
import Chip from '@/components/ui/Chip.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import Notice from '@/components/ui/Notice.vue'
import Panel from '@/components/ui/Panel.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import PageHeader from '@/components/layout/PageHeader.vue'

/** Confirmar el cruce: qué persiste, qué nace y qué se propone cerrar.
 * Mirror de PantallaConfirmarCruce (pantallas/confirmar_cruce.dart). */
const route = useRoute()
const router = useRouter()
const ui = useUiStore()

const auditoriaId = computed(() => Number(route.params.id))
const cruceQuery = useReconciliacionQuery(auditoriaId)
const confirmarMutation = useConfirmarAuditoriaMutation()

const decisiones = reactive<Record<number, Record<string, unknown>>>({})
const publicando = computed(() => confirmarMutation.isPending.value)

const pendientes = computed(() => (cruceQuery.data.value?.pendientes_de_decision as number | undefined) ?? 0)
const porDestino = computed(
  () => (cruceQuery.data.value?.por_destino as Record<string, unknown[]> | undefined) ?? {},
)
const tomadas = computed(() => Object.keys(decisiones).length)

const explicaciones: Record<DestinoReconciliacionValor, string> = {
  persiste: 'El mismo problema sigue reportado. Se mantiene abierto y suma una aparición.',
  nuevo: 'No corresponde a ningún hallazgo vigente ni cerrado de la sede.',
  reincidencia:
    'Figuraba como cerrado y vuelve a reportarse. Un cierre que no resolvió nada es la señal más valiosa que da el sistema.',
  candidato_cierre: 'El estándar se auditó y el problema ya no aparece. Al cerrarlo se puede registrar la evidencia.',
  no_verificado: 'El estándar no se evaluó en esta auditoría, así que sigue abierto sin verificar.',
  ausente_corte: '',
  revision: 'Se parece a un hallazgo vigente, pero no lo suficiente para vincularlos sin mirar.',
  conflicto: 'El mismo hallazgo se tocó por dos lados a la vez.',
}

const opcionesPorDestino: Partial<Record<DestinoReconciliacionValor, Array<[string, string]>>> = {
  candidato_cierre: [
    ['cerrar', 'Cerrar con evidencia'],
    ['mantener', 'Mantener abierto'],
  ],
  reincidencia: [
    ['reabrir', 'Reabrir'],
    ['nuevo', 'Registrar como nuevo'],
  ],
  revision: [
    ['vincular', 'Es el mismo'],
    ['nuevo', 'Es uno nuevo'],
    ['descartar', 'Descartar'],
  ],
  conflicto: [
    ['conservar_app', 'Conservar la app'],
    ['tomar_excel', 'Tomar el Excel'],
  ],
}

async function elegirAccion(id: number, accion: string) {
  if (accion === 'cerrar') {
    const evidencia = await preguntar({
      titulo: '¿Con qué evidencia se cierra?',
      label: 'Evidencia (opcional)',
      placeholder: 'Acta, contrato, registro fotográfico…',
      multilinea: true,
      textoConfirmar: 'Cerrar el hallazgo',
    })
    // null es cancelar; vacío es cerrar sin registrar evidencia.
    if (evidencia === null) return
    decisiones[id] = evidencia === '' ? { accion: 'cerrar' } : { accion: 'cerrar', evidencia }
    return
  }

  if (accion === 'vincular') {
    decisiones[id] = { accion: 'vincular', hallazgo_id: decisiones[id]?.hallazgo_id ?? null }
    return
  }

  decisiones[id] = { accion }
}

async function publicar() {
  if (tomadas.value < pendientes.value) {
    ui.mostrar(`Faltan ${pendientes.value - tomadas.value} decisiones por tomar.`, { error: true })
    return
  }

  try {
    const r = await confirmarMutation.mutateAsync({ auditoriaId: auditoriaId.value, decisiones: { ...decisiones } })
    const aplicado = (r.aplicado as Record<string, unknown> | undefined) ?? {}
    ui.mostrar(
      `Auditoría publicada: ${aplicado.nuevos ?? 0} nuevos, ${aplicado.persiste ?? 0} vigentes, ${aplicado.cerrados ?? 0} cerrados.`,
    )
    router.push({ name: 'cargar-auditoria' })
  } catch (e) {
    ui.mostrar(e instanceof ApiError ? e.mensaje : `${e}`, { error: true })
  }
}
</script>

<template>
  <PageHeader titulo="Confirmar el cruce" con-volver @volver="router.back()" />

  <div v-if="cruceQuery.isPending.value" class="flex justify-center py-20 text-(--color-apagado)">Cargando…</div>
  <ErrorState v-else-if="cruceQuery.isError.value" :error="cruceQuery.error.value" :reintentar="() => cruceQuery.refetch()" />

  <EmptyState
    v-else-if="Object.keys(porDestino).length === 0"
    icono="check-circle"
    titulo="Nada por revisar"
    detalle="Esta auditoría ya fue confirmada."
  />

  <template v-else-if="cruceQuery.data.value">
    <div class="mx-auto max-w-3xl space-y-5 p-4 sm:p-6">
      <div>
        <p class="text-[19px] font-bold">{{ (cruceQuery.data.value.auditoria as Record<string, unknown>)?.sede }}</p>
        <p class="text-[12.5px] text-(--color-apagado)">
          Versión {{ (cruceQuery.data.value.auditoria as Record<string, unknown>)?.version }} ·
          {{ (cruceQuery.data.value.auditoria as Record<string, unknown>)?.periodo }}
        </p>
        <Notice
          class="mt-3.5"
          :icono="pendientes === 0 ? 'check-circle' : 'pending'"
          :color="pendientes === 0 ? 'var(--color-bien)' : 'var(--color-alerta)'"
          :mensaje="
            pendientes === 0
              ? 'Nada exige decisión: se puede publicar directamente.'
              : `${pendientes} ${pendientes === 1 ? 'hallazgo necesita' : 'hallazgos necesitan'} una decisión. Nada se aplica hasta resolverlos.`
          "
        />
      </div>

      <template v-for="destino in DestinoReconciliacion.valores" :key="destino.valor">
        <div v-if="(porDestino[destino.valor] ?? []).length > 0">
          <div class="mb-1.5 flex items-center gap-2.5">
            <StatusBadge :texto="destino.etiqueta" :color="destino.color" />
            <span class="tabular text-[13px] font-bold">{{ (porDestino[destino.valor] ?? []).length }}</span>
            <span class="h-px flex-1 bg-(--color-regla)" />
          </div>
          <p class="mb-2.5 text-[12px] leading-relaxed text-(--color-apagado)">{{ explicaciones[destino.valor] }}</p>

          <div class="space-y-2">
            <Panel
              v-for="fila in porDestino[destino.valor] as unknown as Record<string, unknown>[]"
              :key="fila.id as number"
            >
              <p v-if="fila.texto_entrante" class="text-[13.5px] leading-relaxed">{{ fila.texto_entrante }}</p>

              <div
                v-if="fila.texto_vigente && fila.texto_vigente !== fila.texto_entrante"
                class="mt-2.5 rounded-[3px] bg-(--color-hundido) p-2.5"
              >
                <div class="flex items-center">
                  <span class="text-[9.5px] font-bold tracking-wide text-(--color-apagado) uppercase">Ya registrado</span>
                  <span v-if="fila.similitud != null" class="ml-auto text-[10.5px] text-(--color-apagado)">
                    parecido {{ Math.round((fila.similitud as number) * 100) }} %
                  </span>
                </div>
                <p class="mt-1.5 text-[12.5px] leading-relaxed text-(--color-tinta-2)">{{ fila.texto_vigente }}</p>
              </div>

              <div v-if="destino.exigeConfirmacion" class="mt-3 flex flex-wrap gap-2">
                <Chip
                  v-for="[accion, etiqueta] in opcionesPorDestino[destino.valor] ?? []"
                  :key="accion"
                  :selected="decisiones[fila.id as number]?.accion === accion"
                  @click="elegirAccion(fila.id as number, accion)"
                >
                  {{ etiqueta }}
                </Chip>
                <p
                  v-if="decisiones[fila.id as number]?.accion === 'cerrar'"
                  class="w-full text-[11.5px] italic"
                  style="color: var(--color-bien)"
                >
                  Evidencia: {{ decisiones[fila.id as number]?.evidencia }}
                </p>
              </div>
            </Panel>
          </div>
        </div>
      </template>
    </div>

    <!-- Sticky dentro del <main> con scroll: ocupa su propio espacio, así el
         último hallazgo nunca queda debajo y no tapa la navegación móvil. -->
    <div class="sticky bottom-0 z-10 border-t border-(--color-regla) bg-(--color-superficie) p-4">
      <div class="mx-auto max-w-3xl">
        <p
          v-if="pendientes > 0"
          class="mb-2 text-center text-[12px] font-semibold"
          :style="{ color: tomadas >= pendientes ? 'var(--color-bien)' : 'var(--color-alerta)' }"
        >
          {{ tomadas }} de {{ pendientes }} decisiones tomadas
        </p>
        <AppButton icono="send" block :cargando="publicando" @click="publicar">
          {{ publicando ? 'Publicando…' : 'Publicar auditoría' }}
        </AppButton>
      </div>
    </div>
  </template>
</template>
