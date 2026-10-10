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

const mobile = {
  notifications: () => import('../views/mobile/Notifications.vue'),
  profile: () => import('../views/mobile/Profile.vue'),
}

const routes = [
  // Pages de connexion : e-commerçants à la racine (page d'accueil du site), équipe et livreurs dans leur espace.
  // Une fois connecté, chacun est renvoyé vers son espace, quelle que soit la page utilisée.
  { path: '/', name: 'home', component: () => import('../views/auth/MerchantHome.vue'), meta: { guest: true } },
  { path: '/admin/connexion', name: 'login-staff', component: () => import('../views/auth/StaffLogin.vue'), meta: { guest: true } },
  { path: '/livreur/connexion', name: 'login-courier', component: () => import('../views/auth/CourierLogin.vue'), meta: { guest: true } },
  // Inscription des e-commerçants et mot de passe oublié (numéro vérifié par WhatsApp ou SMS)
  { path: '/inscription', name: 'signup', component: () => import('../views/auth/Signup.vue'), meta: { guest: true } },
  { path: '/mot-de-passe-oublie', name: 'forgot-password', component: () => import('../views/auth/ForgotPassword.vue'), meta: { guest: true } },
  // Ancienne adresse de connexion
  { path: '/login', redirect: (to) => ({ path: '/', query: to.query }) },
  // Pages publiques (destinataire)
  { path: '/b/:slug', name: 'shop', component: () => import('../views/shop/ShopPage.vue'), meta: { public: true } },
  { path: '/suivi/:code?', name: 'tracking', component: () => import('../views/Tracking.vue'), meta: { public: true } },
  {
    path: '/etiquette/:id',
    name: 'label',
    component: () => import('../views/Label.vue'),
    meta: { roles: [...SPACES.admin, ...SPACES.marchand] },
  },
  {
    path: '/bon-de-retour/:id',
    name: 'return-slip',
    component: () => import('../views/ReturnSlipPrint.vue'),
    meta: { roles: [...SPACES.admin, ...SPACES.marchand, ...SPACES.livreur] },
  },
  {
    path: '/admin',
    component: () => import('../layouts/AdminLayout.vue'),
    meta: { roles: SPACES.admin },
    children: [
      { path: '', name: 'admin', component: shared.dashboard },
      { path: 'courses', component: shared.orders, meta: { permission: 'orders.view' } },
      { path: 'courses/nouvelle', component: shared.orderCreate, meta: { permission: 'orders.create' } },
      { path: 'courses/import', component: () => import('../views/admin/OrderImport.vue'), meta: { permission: 'orders.create' } },
      { path: 'courses/:id(\\d+)', component: shared.orderDetail, meta: { permission: 'orders.view' } },
      { path: 'marchands', component: () => import('../views/admin/Merchants.vue'), meta: { permission: 'merchants.view' } },
      { path: 'terrain', component: () => import('../views/admin/FieldReports.vue'), meta: { permission: 'orders.dispatch' } },
      { path: 'carte', component: () => import('../views/admin/CourierMap.vue'), meta: { permission: 'orders.dispatch' } },
      { path: 'colis-livreurs', component: () => import('../views/admin/HeldParcels.vue'), meta: { permission: 'orders.dispatch' } },
      { path: 'retours', component: () => import('../views/admin/MerchantReturns.vue'), meta: { permission: 'orders.dispatch' } },
      { path: 'livreurs', component: () => import('../views/admin/Couriers.vue'), meta: { permission: 'orders.dispatch' } },
      { path: 'utilisateurs', component: () => import('../views/admin/Users.vue'), meta: { permission: 'users.view' } },
      { path: 'zones', component: () => import('../views/admin/Zones.vue'), meta: { permission: 'settings.manage' } },
      { path: 'tarifs', component: () => import('../views/admin/Pricing.vue'), meta: { permission: 'settings.manage' } },
      { path: 'paie-livreurs', component: () => import('../views/admin/PayPlans.vue'), meta: { permission: 'settings.manage' } },
      { path: 'stock', component: () => import('../views/admin/Stock.vue'), meta: { permission: 'stock.manage' } },
      { path: 'caisse', component: () => import('../views/admin/Finance.vue'), meta: { permission: 'finance.view' } },
      { path: 'caisse/reversements/:id(\\d+)', component: shared.payoutDetail, meta: { permission: 'finance.view' } },
      { path: 'parametres', component: () => import('../views/admin/Settings.vue'), meta: { permission: 'settings.manage' } },
      { path: 'integrations', component: () => import('../views/admin/Integrations.vue'), meta: { permission: 'integrations.manage' } },
      { path: 'whatsapp', component: () => import('../views/admin/WhatsApp.vue'), meta: { permission: 'settings.manage' } },
      { path: 'whatsapp/simulateur', component: () => import('../views/admin/WhatsAppSimulator.vue'), meta: { permission: 'settings.manage' } },
      { path: 'messages', component: () => import('../views/admin/Messages.vue'), meta: { permission: 'orders.dispatch' } },
    ],
  },
  // Application livreur (PWA mobile)
  {
    path: '/livreur',
    component: () => import('../views/courier/CourierApp.vue'),
    meta: { roles: SPACES.livreur },
    children: [
      { path: '', name: 'livreur', component: () => import('../views/courier/Missions.vue'), meta: { title: 'Mes missions' } },
      { path: 'missions/:id(\\d+)', component: () => import('../views/courier/MissionDetail.vue'), meta: { title: 'Mission', back: true } },
      { path: 'stock', component: () => import('../views/admin/Stock.vue'), meta: { permission: 'stock.manage' } },
      { path: 'caisse', component: () => import('../views/courier/Wallet.vue'), meta: { title: 'Ma caisse' } },
      { path: 'messages', component: () => import('../views/courier/Messages.vue'), meta: { title: 'Consignes de l\'agence', back: true } },
      { path: 'retours/:id(\\d+)', component: () => import('../views/courier/ReturnHandOver.vue'), meta: { title: 'Remise au marchand', back: true } },
      { path: 'notifications', component: mobile.notifications, meta: { title: 'Notifications', back: true } },
      { path: 'profil', component: mobile.profile, meta: { title: 'Mon profil' } },
      // Les notifications pointent vers /livreur/courses/:id : on renvoie vers la liste des missions
      { path: 'courses/:id', redirect: '/livreur' },
    ],
  },
  // Console du super administrateur (admin.jibiat.com) : les entreprises de la plateforme
  {
    path: '/console',
    component: () => import('../layouts/ConsoleLayout.vue'),
    meta: { roles: SPACES.console },
    children: [
      { path: '', name: 'console', component: () => import('../views/console/Companies.vue') },
      { path: 'entreprises/:id(\\d+)', component: () => import('../views/console/Company.vue') },
    ],
  },
  // Application e-commerçant (PWA mobile)
  {
    path: '/marchand',
    component: () => import('../views/merchant/MerchantApp.vue'),
    meta: { roles: SPACES.marchand },
    children: [
      { path: '', name: 'marchand', component: () => import('../views/merchant/Home.vue') },
      { path: 'courses', component: () => import('../views/merchant/Orders.vue'), meta: { title: 'Mes courses' } },
      { path: 'courses/nouvelle', component: () => import('../views/merchant/NewOrder.vue'), meta: { title: 'Nouvelle course', back: true } },
      { path: 'courses/import', component: () => import('../views/merchant/OrderImport.vue'), meta: { title: 'Importer des courses', back: true, permission: 'orders.create' } },
      { path: 'boutique', component: () => import('../views/merchant/Shop.vue'), meta: { title: 'Ma boutique en ligne', back: true, permission: 'integrations.manage' } },
      { path: 'integrations', component: () => import('../views/merchant/Integrations.vue'), meta: { title: 'Intégrations', back: true, permission: 'integrations.manage' } },
      { path: 'courses/:id(\\d+)', component: () => import('../views/merchant/OrderView.vue'), meta: { title: 'Course', back: true } },
      { path: 'stock', component: () => import('../views/merchant/Stock.vue'), meta: { title: 'Mon stock' } },
      { path: 'lieu-de-ramassage', component: () => import('../views/merchant/PickupLocation.vue'), meta: { title: 'Lieu de ramassage', back: true } },
      { path: 'paiements', component: () => import('../views/merchant/Payments.vue'), meta: { title: 'Paiements', permission: 'finance.view' } },
      { path: 'paiements/:id(\\d+)', component: () => import('../views/merchant/PayoutView.vue'), meta: { title: 'Relevé', back: true, permission: 'finance.view' } },
      { path: 'notifications', component: mobile.notifications, meta: { title: 'Notifications', back: true } },
      { path: 'profil', component: mobile.profile, meta: { title: 'Mon profil' } },
    ],
  },
  { path: '/:pathMatch(.*)*', redirect: '/' },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

// Page de connexion de l'espace demandé, avec retour à la page voulue après connexion
function loginFor(to) {
  const login = to.path.startsWith('/admin') || to.path.startsWith('/console') ? '/admin/connexion' : to.path.startsWith('/livreur') ? '/livreur/connexion' : '/'
  return { path: login, query: to.path !== login && !['/admin', '/livreur', '/marchand', '/console'].includes(to.path) ? { redirect: to.fullPath } : {} }
}

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
    return to.meta.guest ? true : loginFor(to)
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
