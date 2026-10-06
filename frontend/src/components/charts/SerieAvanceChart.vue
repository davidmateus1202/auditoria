<script setup lang="ts">
import { computed, ref } from 'vue'

import { Formato } from '@/core/format'
import type { PuntoSerie } from '@/domain/models'

/**
 * Serie mensual de avance acumulado (% cerrados). Una sola serie, así que no
 * necesita leyenda — el título ya la nombra — pero sí una capa de hover con
 * crosshair y tooltip (skill `dataviz`, interaction.md). Mirror del
 * LineChart de fl_chart en app/lib/pantallas/serie.dart.
 */
const props = defineProps<{ puntos: PuntoSerie[] }>()

const ancho = 600
const alto = 220
const margen = { top: 16, right: 12, bottom: 28, left: 42 }
const anchoGrafico = ancho - margen.left - margen.right
const altoGrafico = alto - margen.top - margen.bottom

const valores = computed(() => props.puntos.map((p) => (p.generales.pctAvance ?? 0) * 100))

// Techo redondeado hacia arriba para que la línea no toque el borde y las
// etiquetas del eje nombren valores que el gráfico alcanza.
const techo = computed(() => {
  const maximo = Math.max(...valores.value, 0)
  return Math.min(100, Math.max(20, Math.ceil(maximo / 20) * 20))
})

function x(i: number): number {
  const n = props.puntos.length
  return n <= 1 ? margen.left : margen.left + (i / (n - 1)) * anchoGrafico
}

function y(valor: number): number {
  return margen.top + altoGrafico - (valor / techo.value) * altoGrafico
}

const puntosLinea = computed(() => valores.value.map((v, i) => ({ x: x(i), y: y(v) })))
const rutaLinea = computed(() => puntosLinea.value.map((p, i) => `${i === 0 ? 'M' : 'L'}${p.x},${p.y}`).join(' '))
const rutaArea = computed(
  () => `${rutaLinea.value} L${x(props.puntos.length - 1)},${margen.top + altoGrafico} L${margen.left},${margen.top + altoGrafico} Z`,
)

const lineasGrid = computed(() => {
  const pasos = 4
  return Array.from({ length: pasos + 1 }, (_, i) => {
    const valor = (techo.value / pasos) * i
    return { valor, y: y(valor) }
  })
})

const activo = ref<number | null>(null)

function alMover(evento: MouseEvent, svg: SVGSVGElement) {
  const rect = svg.getBoundingClientRect()
  const xRelativo = ((evento.clientX - rect.left) / rect.width) * ancho
  let masCercano = 0
  let distanciaMinima = Infinity
  for (let i = 0; i < props.puntos.length; i++) {
    const distancia = Math.abs(x(i) - xRelativo)
    if (distancia < distanciaMinima) {
      distanciaMinima = distancia
      masCercano = i
    }
  }
  activo.value = masCercano
}
</script>

<template>
  <svg
    :viewBox="`0 0 ${ancho} ${alto}`"
    class="w-full"
    preserveAspectRatio="xMidYMid meet"
    @mousemove="(e) => alMover(e, e.currentTarget as SVGSVGElement)"
    @mouseleave="activo = null"
  >
    <!-- grilla recesiva -->
    <g v-for="linea in lineasGrid" :key="linea.valor">
      <line :x1="margen.left" :x2="ancho - margen.right" :y1="linea.y" :y2="linea.y" stroke="var(--color-regla)" stroke-width="1" />
      <text :x="margen.left - 8" :y="linea.y" text-anchor="end" dominant-baseline="middle" font-size="10" fill="var(--color-apagado)">
        {{ Math.round(linea.valor) }}%
      </text>
    </g>

    <!-- ejes -->
    <line :x1="margen.left" :x2="margen.left" :y1="margen.top" :y2="margen.top + altoGrafico" stroke="var(--color-regla)" />
    <line :x1="margen.left" :x2="ancho - margen.right" :y1="margen.top + altoGrafico" :y2="margen.top + altoGrafico" stroke="var(--color-regla)" />

    <!-- área y línea -->
    <path :d="rutaArea" fill="var(--color-bien)" opacity="0.1" />
    <path :d="rutaLinea" fill="none" stroke="var(--color-bien)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />

    <!-- marcadores -->
    <circle
      v-for="(p, i) in puntosLinea"
      :key="i"
      :cx="p.x"
      :cy="p.y"
      :r="activo === i ? 5 : 3.5"
      fill="var(--color-bien)"
      stroke="var(--color-superficie)"
      stroke-width="2"
    />

    <!-- etiquetas eje x (mes) -->
    <text
      v-for="(punto, i) in puntos"
      :key="punto.periodo"
      :x="x(i)"
      :y="alto - 8"
      text-anchor="middle"
      font-size="10"
      fill="var(--color-apagado)"
    >
      {{ punto.periodo.slice(5) }}
    </text>

    <!-- crosshair + tooltip -->
    <template v-if="activo !== null">
      <line
        :x1="x(activo)"
        :x2="x(activo)"
        :y1="margen.top"
        :y2="margen.top + altoGrafico"
        stroke="var(--color-tinta-2)"
        stroke-width="1"
        stroke-dasharray="3 3"
      />
      <g :transform="`translate(${Math.min(Math.max(x(activo), margen.left + 55), ancho - margen.right - 55)}, ${margen.top + 10})`">
        <rect x="-55" y="-10" width="110" height="34" rx="3" fill="var(--color-tinta)" />
        <text x="0" y="2" text-anchor="middle" font-size="10" fill="white">{{ puntos[activo].periodo }}</text>
        <text x="0" y="16" text-anchor="middle" font-size="11" font-weight="700" fill="white">
          {{ Formato.porcentaje(puntos[activo].generales.pctAvance) }} de avance
        </text>
      </g>
    </template>
  </svg>
</template>
