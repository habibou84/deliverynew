import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { SPACES } from '../roles'

const shared = {
  dashboard: () => import('../views/shared/Dashboard.vue'),
  orders: () => import('../views/shared/OrderList.vue'),
  orderCreate: () => import('../views/shared/OrderCreate.vue'),
  orderDetail: () => import('../views/shared/OrderDetail.vue'),
  payoutDetail: () => import('../views/shared/PayoutDetail.vue'),
}

const routes = [
  {
    path: '/login',
    name: 'login',
    component: () => import('../views/login.vue'),
    meta: { guest: true },
  },
  // Pages publiques (destinataire)
  { path: '/suivi/:code?', name: 'tracking', component: () => import('../views/Tracking.vue'), meta: { public: true } },
  {
    path: '/etiquette/:id',
    name: 'label',
    component: () => import('../views/Label.vue'),
    meta: { roles: [...SPACES.admin, ...SPACES.marchand] },
  },
  {
    path: '/admin',
    component: () => import('../layouts/AdminLayout.vue'),
    meta: { roles: SPACES.admin },
    children: [
      { path: '', name: 'admin', component: shared.dashboard },
      { path: 'courses', component: shared.orders, meta: { permission: 'orders.view' } },
      { path: 'courses/nouvelle', component: shared.orderCreate, meta: { permission: 'orders.create' } },
      { path: 'courses/:id(\\d+)', component: shared.orderDetail, meta: { permission: 'orders.view' } },
      { path: 'marchands', component: () => import('../views/admin/Merchants.vue'), meta: { permission: 'merchants.view' } },
      { path: 'livreurs', component: () => import('../views/admin/Couriers.vue'), meta: { permission: 'orders.dispatch' } },
      { path: 'utilisateurs', component: () => import('../views/admin/Users.vue'), meta: { permission: 'users.view' } },
      { path: 'zones', component: () => import('../views/admin/Zones.vue'), meta: { permission: 'settings.manage' } },
      { path: 'tarifs', component: () => import('../views/admin/Pricing.vue'), meta: { permission: 'settings.manage' } },
      { path: 'caisse', component: () => import('../views/admin/Finance.vue'), meta: { permission: 'finance.view' } },
      { path: 'caisse/reversements/:id(\\d+)', component: shared.payoutDetail, meta: { permission: 'finance.view' } },
      { path: 'parametres', component: () => import('../views/admin/Settings.vue'), meta: { permission: 'settings.manage' } },
    ],
  },
  {
    path: '/livreur',
    component: () => import('../layouts/LivreurLayout.vue'),
    meta: { roles: SPACES.livreur },
    children: [
      { path: '', name: 'livreur', component: () => import('../views/courier/Missions.vue') },
      { path: 'missions/:id(\\d+)', component: () => import('../views/courier/MissionDetail.vue') },
      // Les notifications pointent vers /livreur/courses/:id : on renvoie vers la liste des missions
      { path: 'courses/:id', redirect: '/livreur' },
    ],
  },
  {
    path: '/marchand',
    component: () => import('../layouts/ClientLayout.vue'),
    meta: { roles: SPACES.marchand },
    children: [
      { path: '', name: 'marchand', component: shared.dashboard },
      { path: 'courses', component: shared.orders },
      { path: 'courses/nouvelle', component: shared.orderCreate },
      { path: 'courses/:id(\\d+)', component: shared.orderDetail },
      { path: 'paiements', component: () => import('../views/merchant/Payments.vue'), meta: { permission: 'finance.view' } },
      { path: 'paiements/:id(\\d+)', component: shared.payoutDetail, meta: { permission: 'finance.view' } },
    ],
  },
  { path: '/:pathMatch(.*)*', redirect: '/' },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

router.beforeEach(async (to) => {
  if (to.meta.public) return true

  const auth = useAuthStore()

  if (auth.isAuthenticated && !auth.user) {
    try {
      await auth.fetchUser()
    } catch {
      auth.clear()
    }
  }

  if (!auth.user) {
    return to.meta.guest ? true : { name: 'login', query: to.path !== '/' ? { redirect: to.fullPath } : {} }
  }

  // Rôles autorisés : on regarde toute la chaîne de routes (layout + page)
  const roles = to.matched.map((r) => r.meta.roles).filter(Boolean).at(-1)
  const permission = to.meta.permission

  if (to.meta.guest || to.path === '/' || (roles && !roles.includes(auth.role)) || (permission && !auth.can(permission))) {
    return auth.homeRoute === to.path ? true : auth.homeRoute
  }

  return true
})

export default router
