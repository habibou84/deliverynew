import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { CONSOLE_ROLES, SPACES } from '../roles'
import { allowedInApp, appLock, brandedCompany, nativeApp, nativeCompany } from '../native'
import { spaceForPath } from '../bootstrap/session'

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
  // Applications Android et iOS : choix de l'entreprise de livraison (code), avant la connexion
  // Bouton « Connecter WhatsApp » : fenêtre de Meta ouverte depuis jibiat.com (ticket de l'espace de l'entreprise)
  { path: '/connecter-whatsapp', name: 'whatsapp-connect', component: () => import('../views/auth/WhatsAppConnect.vue'), meta: { public: true } },
  // Connexion en un clic d'une application (Jibiat Marchand) au compte de l'e-commerçant : la page gère
  // elle-même la session de l'espace e-commerçant (connexion, ouverture de compte ou demande, autorisation)
  { path: '/marchand/connecter', name: 'app-connect', component: () => import('../views/auth/AppConnect.vue'), meta: { public: true } },
  { path: '/entreprise', name: 'native-company', component: () => import('../views/auth/NativeCompany.vue'), meta: { public: true } },
  // Pages de connexion : e-commerçants à la racine (page d'accueil du site), équipe et livreurs dans leur espace.
  // Une fois connecté, chacun est renvoyé vers son espace, quelle que soit la page utilisée.
  { path: '/', name: 'home', component: () => import('../views/auth/MerchantHome.vue'), meta: { guest: true } },
  { path: '/admin/connexion', name: 'login-staff', component: () => import('../views/auth/StaffLogin.vue'), meta: { guest: true } },
  // Application e-commerçant installée : sa connexion reste dans son espace (/marchand), sinon
  // Chrome la confond avec l'application livreur (une application couvrant « / » couvre aussi /livreur)
  { path: '/marchand/connexion', name: 'login-merchant-app', component: () => import('../views/auth/MerchantHome.vue'), meta: { guest: true } },
  { path: '/livreur/connexion', name: 'login-courier', component: () => import('../views/auth/CourierLogin.vue'), meta: { guest: true } },
  // Inscription des e-commerçants et mot de passe oublié (numéro vérifié par WhatsApp ou SMS)
  { path: '/inscription', name: 'signup', component: () => import('../views/auth/Signup.vue'), meta: { guest: true } },
  // Inscription d'une entreprise de livraison (adresse de la console : admin.jibiat.com/creer-mon-entreprise)
  { path: '/creer-mon-entreprise', name: 'company-signup', component: () => import('../views/auth/CompanySignup.vue'), meta: { public: true } },
  { path: '/mot-de-passe-oublie', name: 'forgot-password', component: () => import('../views/auth/ForgotPassword.vue'), meta: { guest: true } },
  // Invitation d'un membre de l'équipe ou d'un livreur (lien envoyé par WhatsApp)
  { path: '/invitation/:token', name: 'invitation', component: () => import('../views/auth/Invitation.vue'), meta: { public: true } },
  // Ancienne adresse de connexion
  { path: '/login', redirect: (to) => ({ path: '/', query: to.query }) },
  // Pages publiques (destinataire)
  { path: '/b/:slug', name: 'shop', component: () => import('../views/shop/ShopPage.vue'), meta: { public: true } },
  { path: '/suivi/:code?', name: 'tracking', component: () => import('../views/Tracking.vue'), meta: { public: true } },
  // Avis du destinataire sur sa livraison (lien envoyé sur WhatsApp)
  { path: '/avis/:token', name: 'rating', component: () => import('../views/Rating.vue'), meta: { public: true } },
  {
    path: '/etiquette/:id',
    name: 'label',
    component: () => import('../views/Label.vue'),
    meta: { roles: [...SPACES.admin, ...SPACES.marchand] },
  },
  // Plusieurs étiquettes d'un coup : /etiquettes?ids=12,15,18
  {
    path: '/etiquettes',
    name: 'labels',
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
      { path: 'statistiques', component: () => import('../views/admin/Statistics.vue'), meta: { permission: 'orders.view' } },
      { path: 'courses', component: shared.orders, meta: { permission: 'orders.view' } },
      { path: 'courses/nouvelle', component: shared.orderCreate, meta: { permission: 'orders.create' } },
      { path: 'courses/import', component: () => import('../views/admin/OrderImport.vue'), meta: { permission: 'orders.create' } },
      { path: 'courses/:id(\\d+)', component: shared.orderDetail, meta: { permission: 'orders.view' } },
      { path: 'marchands', component: () => import('../views/admin/Merchants.vue'), meta: { permission: 'merchants.view' } },
      { path: 'premium', component: () => import('../views/admin/PremiumProgram.vue'), meta: { permission: 'merchants.view' } },
      // Activité des livreurs : suivi du jour et, en onglets, carte, disponibles, colis en main, remontées terrain, planning
      {
        path: 'activite',
        component: () => import('../views/admin/activity/CourierActivity.vue'),
        meta: { permission: 'orders.dispatch' },
        children: [
          { path: '', component: () => import('../views/admin/activity/DailyTracking.vue'), meta: { permission: 'orders.dispatch' } },
          { path: 'carte', component: () => import('../views/admin/CourierMap.vue'), meta: { permission: 'orders.dispatch' } },
          { path: 'disponibles', component: () => import('../views/admin/CourierAvailability.vue'), meta: { permission: 'orders.dispatch' } },
          { path: 'colis', component: () => import('../views/admin/HeldParcels.vue'), meta: { permission: 'orders.dispatch' } },
          { path: 'remontees', component: () => import('../views/admin/FieldReports.vue'), meta: { permission: 'orders.dispatch' } },
          { path: 'planning', component: () => import('../views/admin/Planning.vue'), meta: { permission: 'orders.dispatch' } },
          { path: 'mural', component: () => import('../views/admin/activity/WallScreen.vue'), meta: { permission: 'orders.dispatch' } },
        ],
      },
      // Anciennes adresses (liens des notifications, favoris) : vers l'onglet, en gardant les paramètres
      { path: 'carte', redirect: (to) => ({ path: '/admin/activite/carte', query: to.query }) },
      { path: 'disponibilites', redirect: (to) => ({ path: '/admin/activite/disponibles', query: to.query }) },
      { path: 'colis-livreurs', redirect: (to) => ({ path: '/admin/activite/colis', query: to.query }) },
      { path: 'terrain', redirect: (to) => ({ path: '/admin/activite/remontees', query: to.query }) },
      { path: 'planning', redirect: (to) => ({ path: '/admin/activite/planning', query: to.query }) },
      { path: 'retours', component: () => import('../views/admin/MerchantReturns.vue'), meta: { permission: 'orders.dispatch' } },
      { path: 'livreurs', component: () => import('../views/admin/Couriers.vue'), meta: { permission: 'orders.dispatch' } },
      { path: 'livreurs/notes', component: () => import('../views/admin/CourierRatings.vue'), meta: { permission: 'orders.dispatch' } },
      { path: 'vehicules', component: () => import('../views/admin/Vehicles.vue'), meta: { permission: 'orders.dispatch' } },
      { path: 'livreurs/:id(\\d+)/notes', component: () => import('../views/admin/CourierRatingDetail.vue'), meta: { permission: 'orders.dispatch' } },
      { path: 'utilisateurs', component: () => import('../views/admin/Users.vue'), meta: { permission: 'users.view' } },
      { path: 'zones', component: () => import('../views/admin/Zones.vue'), meta: { permission: 'settings.manage' } },
      { path: 'tarifs', component: () => import('../views/admin/Pricing.vue'), meta: { permission: 'settings.manage' } },
      { path: 'paie-livreurs', component: () => import('../views/admin/PayPlans.vue'), meta: { permission: 'settings.manage' } },
      { path: 'stock', component: () => import('../views/admin/Stock.vue'), meta: { permission: 'stock.manage', feature: 'stock' } },
      { path: 'caisse', component: () => import('../views/admin/Finance.vue'), meta: { permission: 'finance.view' } },
      { path: 'caisse/reversements/:id(\\d+)', component: shared.payoutDetail, meta: { permission: 'finance.view' } },
      { path: 'caisse/versements/:id(\\d+)', component: () => import('../views/admin/RemittanceReceipt.vue'), meta: { permission: 'finance.view' } },
      { path: 'caisse/journal', component: () => import('../views/admin/CashJournal.vue'), meta: { permission: 'finance.view' } },
      { path: 'caisse/cloture', component: () => import('../views/admin/CashClosing.vue'), meta: { permission: 'finance.view' } },
      // Réglages par thèmes (l'ancienne page « Paramètres » y mène)
      { path: 'mise-en-route', component: () => import('../views/admin/SetupGuide.vue'), meta: { permission: 'settings.manage' } },
      { path: 'reglages', component: () => import('../views/admin/settings/SettingsHub.vue'), meta: { permission: ['settings.manage', 'integrations.manage', 'users.view'] } },
      { path: 'reglages/entreprise', component: () => import('../views/admin/settings/SettingsCompany.vue'), meta: { permission: 'settings.manage' } },
      { path: 'reglages/courses', component: () => import('../views/admin/settings/SettingsOrders.vue'), meta: { permission: 'settings.manage' } },
      { path: 'reglages/alertes', component: () => import('../views/admin/settings/SettingsAlerts.vue'), meta: { permission: 'settings.manage' } },
      { path: 'reglages/e-commercants', component: () => import('../views/admin/settings/SettingsMerchants.vue'), meta: { permission: 'settings.manage' } },
      { path: 'reglages/reprise', component: () => import('../views/admin/settings/SettingsLegacy.vue'), meta: { permission: 'settings.manage' } },
      { path: 'reglages/reprise/brancher', component: () => import('../views/admin/settings/LegacyStep.vue'), meta: { permission: 'settings.manage', step: 'connect' } },
      { path: 'reglages/reprise/comptes', component: () => import('../views/admin/settings/LegacyStep.vue'), meta: { permission: 'settings.manage', step: 'accounts' } },
      { path: 'reglages/reprise/commandes', component: () => import('../views/admin/settings/LegacyStep.vue'), meta: { permission: 'settings.manage', step: 'orders' } },
      { path: 'reglages/reprise/controles', component: () => import('../views/admin/settings/LegacyStep.vue'), meta: { permission: 'settings.manage', step: 'checks' } },
      { path: 'reglages/reprise/bascule', component: () => import('../views/admin/settings/LegacyStep.vue'), meta: { permission: 'settings.manage', step: 'switch' } },
      { path: 'reglages/reprise/zones', component: () => import('../views/admin/settings/LegacyZoneLinks.vue'), meta: { permission: 'settings.manage' } },
      { path: 'reglages/reprise/tarifs', component: () => import('../views/admin/settings/LegacyPrices.vue'), meta: { permission: 'settings.manage' } },
      { path: 'reglages/reprise/a-verifier', component: () => import('../views/admin/settings/LegacyPurgeCheck.vue'), meta: { permission: 'settings.manage' } },
      { path: 'reglages/reprise/supprimees', component: () => import('../views/admin/settings/LegacyDeletedCheck.vue'), meta: { permission: 'settings.manage' } },
      { path: 'reglages/reprise/argent-livreurs', component: () => import('../views/admin/settings/LegacyCashBaseline.vue'), meta: { permission: 'settings.manage' } },
      { path: 'reglages/assistance', component: () => import('../views/admin/settings/SettingsSupport.vue'), meta: { permission: 'settings.manage' } },
      { path: 'parametres', redirect: '/admin/reglages' },
      { path: 'mot-de-passe', component: () => import('../views/admin/Password.vue') },
      { path: 'integrations', component: () => import('../views/admin/Integrations.vue'), meta: { permission: 'integrations.manage', feature: 'api' } },
      { path: 'abonnement', component: () => import('../views/admin/Subscription.vue'), meta: { permission: 'settings.manage' } },
      { path: 'abonnement/factures/:id(\\d+)', component: () => import('../views/shared/PlatformInvoiceView.vue'), meta: { permission: 'settings.manage' } },
      { path: 'whatsapp', component: () => import('../views/admin/WhatsApp.vue'), meta: { permission: 'settings.manage' } },
      { path: 'whatsapp/simulateur', component: () => import('../views/admin/WhatsAppSimulator.vue'), meta: { permission: 'settings.manage', feature: 'whatsapp_bot' } },
      { path: 'messages', component: () => import('../views/admin/Messages.vue'), meta: { permission: 'orders.dispatch' } },
    ],
  },
  // Application livreur : écran « appel entrant » d'une nouvelle mission (sonnerie Android), en plein écran
  { path: '/livreur/appel', component: () => import('../views/courier/IncomingMission.vue'), meta: { roles: SPACES.livreur } },
  // Application livreur (PWA mobile)
  {
    path: '/livreur',
    component: () => import('../views/courier/CourierApp.vue'),
    meta: { roles: SPACES.livreur },
    children: [
      { path: '', name: 'livreur', component: () => import('../views/courier/Missions.vue'), meta: { title: 'Mes missions' } },
      { path: 'missions/:id(\\d+)', component: () => import('../views/courier/MissionDetail.vue'), meta: { title: 'Mission', back: true } },
      { path: 'ramassage/:key', component: () => import('../views/courier/PickupGroup.vue'), meta: { title: 'Ramassage', back: true } },
      { path: 'stock', component: () => import('../views/admin/Stock.vue'), meta: { permission: 'stock.manage', feature: 'stock' } },
      { path: 'caisse', component: () => import('../views/courier/Wallet.vue'), meta: { title: 'Ma caisse' } },
      { path: 'messages', component: () => import('../views/courier/Messages.vue'), meta: { title: 'Consignes de l\'agence', back: true } },
      { path: 'relais', component: () => import('../views/courier/Relays.vue'), meta: { title: 'Relais', back: true } },
      { path: 'retours/:id(\\d+)', component: () => import('../views/courier/ReturnHandOver.vue'), meta: { title: 'Remise au marchand', back: true } },
      { path: 'notifications', component: mobile.notifications, meta: { title: 'Notifications', back: true } },
      { path: 'profil', component: mobile.profile, meta: { title: 'Mon profil' } },
      { path: 'sonnerie', component: () => import('../views/courier/RingSettings.vue'), meta: { title: 'Sonnerie des missions', back: true } },
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
      { path: '', name: 'console', component: () => import('../views/console/Dashboard.vue') },
      { path: 'entreprises', component: () => import('../views/console/Companies.vue') },
      { path: 'entreprises/:id(\\d+)', component: () => import('../views/console/Company.vue') },
      { path: 'formules', component: () => import('../views/console/Plans.vue') },
      { path: 'premium', component: () => import('../views/console/Premium.vue') },
      { path: 'applications', component: () => import('../views/console/BrandedApps.vue') },
      { path: 'facturation', component: () => import('../views/console/Billing.vue'), meta: { roles: CONSOLE_ROLES.accounting } },
      { path: 'paiements', component: () => import('../views/console/WavePayments.vue'), meta: { roles: CONSOLE_ROLES.accounting } },
      { path: 'whatsapp', component: () => import('../views/console/WhatsApp.vue'), meta: { roles: CONSOLE_ROLES.support } },
      { path: 'journal', component: () => import('../views/console/Audit.vue'), meta: { roles: CONSOLE_ROLES.owner } },
      { path: 'sante', component: () => import('../views/console/Health.vue'), meta: { roles: CONSOLE_ROLES.support } },
      { path: 'equipe', component: () => import('../views/console/Team.vue'), meta: { roles: CONSOLE_ROLES.owner } },
      { path: 'compte', component: () => import('../views/console/Account.vue') },
      { path: 'factures/:id(\\d+)', component: () => import('../views/shared/PlatformInvoiceView.vue'), meta: { roles: CONSOLE_ROLES.accounting } },
    ],
  },
  // Application e-commerçant (PWA mobile)
  {
    path: '/marchand',
    component: () => import('../views/merchant/MerchantApp.vue'),
    meta: { roles: SPACES.marchand },
    children: [
      { path: '', name: 'marchand', component: () => import('../views/merchant/Home.vue') },
      { path: 'courses', component: () => import('../views/merchant/Orders.vue'), meta: { title: 'Courses' } },
      { path: 'statistiques', component: () => import('../views/merchant/Stats.vue'), meta: { title: 'Mes statistiques', back: true } },
      { path: 'courses/nouvelle', component: () => import('../views/merchant/NewOrder.vue'), meta: { title: 'Nouvelle course', back: true, hideTabs: true } },
      { path: 'courses/plusieurs', component: () => import('../views/merchant/MultiOrder.vue'), meta: { title: 'Plusieurs courses', back: true, hideTabs: true, permission: 'orders.create' } },
      { path: 'courses/import', component: () => import('../views/merchant/OrderImport.vue'), meta: { title: 'Importer des courses', back: true, permission: 'orders.create' } },
      { path: 'boutique', component: () => import('../views/merchant/Shop.vue'), meta: { title: 'Ma boutique en ligne', back: true, permission: 'integrations.manage', feature: 'shop' } },
      { path: 'integrations', component: () => import('../views/merchant/Integrations.vue'), meta: { title: 'Intégrations', back: true, permission: 'integrations.manage', feature: 'api' } },
      { path: 'courses/:id(\\d+)', component: () => import('../views/merchant/OrderView.vue'), meta: { title: 'Course', back: true, hideTabs: true } },
      { path: 'stock', component: () => import('../views/merchant/Stock.vue'), meta: { title: 'Mon stock', feature: 'stock' } },
      { path: 'lieu-de-ramassage', component: () => import('../views/merchant/PickupLocation.vue'), meta: { title: 'Lieu de ramassage', back: true } },
      { path: 'paiements', component: () => import('../views/merchant/Payments.vue'), meta: { title: 'Mon argent', permission: 'finance.view' } },
      { path: 'paiements/:id(\\d+)', component: () => import('../views/merchant/PayoutView.vue'), meta: { title: 'Relevé', back: true, permission: 'finance.view' } },
      { path: 'moyens-de-paiement', component: () => import('../views/merchant/PaymentMethods.vue'), meta: { title: 'Comment être payé', back: true, permission: 'finance.view' } },
      { path: 'notifications', component: () => import('../views/merchant/Notifications.vue'), meta: { title: 'Notifications', back: true } },
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
  const login = appLock()?.login || (to.path.startsWith('/admin') || to.path.startsWith('/console') ? '/admin/connexion' : to.path.startsWith('/livreur') ? '/livreur/connexion' : '/')
  return { path: login, query: to.path !== login && !['/admin', '/livreur', '/marchand', '/console'].includes(to.path) ? { redirect: to.fullPath } : {} }
}

router.beforeEach(async (to) => {
  const auth = useAuthStore()

  // Application Android : d'abord le code de l'entreprise
  if (nativeApp && !nativeCompany()) return to.path === '/entreprise' ? true : '/entreprise'
  // Application au nom d'une entreprise : pas d'écran « Code de votre entreprise »
  if (brandedCompany && to.path === '/entreprise') return appLock()?.login || '/'

  // Application (Android, ou installée depuis le navigateur) : seulement les pages de son espace
  const lock = appLock()
  if (lock) {
    if (!allowedInApp(to.path) || (to.path === '/entreprise' && !nativeApp)) return auth.isAuthenticated ? lock.home : lock.login
    if (to.path === '/' && lock.login !== '/') return auth.isAuthenticated ? lock.home : lock.login
  }

  // Chaque espace a sa propre session (e-commerçant, livreur, équipe)
  auth.useSpace(spaceForPath(to.path))

  if (to.meta.public) return true

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

  // Module non inclus dans la formule de l'entreprise : retour à l'accueil de l'espace
  const missingFeature = to.meta.feature && !auth.hasFeature(to.meta.feature)

  if (to.meta.guest || to.path === '/' || (roles && !roles.includes(auth.role)) || (permission && !auth.can(permission)) || missingFeature) {
    return auth.homeRoute === to.path ? true : auth.homeRoute
  }

  return true
})

// Nouvelle version mise en ligne pendant que la page était ouverte : les fichiers des pages pas encore visitées
// ont changé de nom et l'ancien n'existe plus. Sans cela, le clic dans le menu ne fait rien jusqu'au rafraîchissement.
// On recharge donc la page demandée (une seule fois par minute, pour ne jamais tourner en boucle).
const RELOAD_KEY = 'reloaded-for-new-version'
function reloadForNewVersion(path) {
  try {
    const last = Number(sessionStorage.getItem(RELOAD_KEY) || 0)
    if (Date.now() - last < 60000) return false
    sessionStorage.setItem(RELOAD_KEY, String(Date.now()))
  } catch {
    // Stockage indisponible : on recharge quand même
  }
  window.location.assign(path)
  return true
}
const isStaleChunk = (error) => /dynamically imported module|Importing a module script failed|error loading dynamically imported module|Failed to load module script/i.test(error?.message || '')

router.onError((error, to) => {
  if (isStaleChunk(error) && reloadForNewVersion(to?.fullPath || window.location.pathname)) return
  console.error(error)
})
export default router
