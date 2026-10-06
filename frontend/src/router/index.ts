import { createRouter, createWebHistory } from 'vue-router'

import { useSessionStore } from '@/stores/session.store'

const AppShell = () => import('@/components/layout/AppShell.vue')
const LoginView = () => import('@/views/LoginView.vue')
const DashboardView = () => import('@/views/DashboardView.vue')
const HallazgosView = () => import('@/views/HallazgosView.vue')
const HallazgoDetalleView = () => import('@/views/HallazgoDetalleView.vue')
const CargarAuditoriaView = () => import('@/views/CargarAuditoriaView.vue')
const ConfirmarCruceView = () => import('@/views/ConfirmarCruceView.vue')
const CorteView = () => import('@/views/CorteView.vue')
const CorteHallazgosView = () => import('@/views/CorteHallazgosView.vue')
const ConsolidadoView = () => import('@/views/ConsolidadoView.vue')
const SerieView = () => import('@/views/SerieView.vue')
const EvidenciasView = () => import('@/views/EvidenciasView.vue')

const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/login', name: 'login', component: LoginView, meta: { publico: true } },
    {
      path: '/',
      component: AppShell,
      children: [
        { path: '', name: 'dashboard', component: DashboardView },
        { path: 'hallazgos', name: 'hallazgos', component: HallazgosView },
        { path: 'hallazgos/:id', name: 'hallazgo-detalle', component: HallazgoDetalleView, props: true },
        { path: 'cargar-auditoria', name: 'cargar-auditoria', component: CargarAuditoriaView },
        // «Archivos cargados» se fusionó dentro de Cargar auditoría: se
        // conserva el enlace viejo por si alguien lo tiene guardado.
        { path: 'auditorias', redirect: { name: 'cargar-auditoria' } },
        {
          path: 'auditorias/:id/confirmar',
          name: 'confirmar-cruce',
          component: ConfirmarCruceView,
          props: true,
        },
        { path: 'corte', name: 'corte', component: CorteView },
        { path: 'corte/:periodo/hallazgos', name: 'corte-hallazgos', component: CorteHallazgosView, props: true },
        { path: 'consolidado', name: 'consolidado', component: ConsolidadoView },
        { path: 'consolidado/serie', name: 'serie', component: SerieView },
        { path: 'evidencias', name: 'evidencias', component: EvidenciasView },
      ],
    },
    { path: '/:pathMatch(.*)*', redirect: '/' },
  ],
})

// Mirror de Puerta (app/lib/main.dart): decide entre ingreso y aplicación
// según haya sesión, esperando primero a que se resuelva el token guardado.
router.beforeEach(async (to) => {
  const session = useSessionStore()
  await session.init()

  const esPublica = to.meta.publico === true

  if (!esPublica && !session.autenticado) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }

  if (esPublica && session.autenticado) {
    return { name: 'dashboard' }
  }

  return true
})

export default router
