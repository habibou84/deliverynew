import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { SPACES } from '../roles'

const routes = [
  {
    path: '/login',
    name: 'login',
    component: () => import('../views/login.vue'),
    meta: { guest: true },
  },
  {
    path: '/admin',
    name: 'admin',
    component: () => import('../layouts/AdminLayout.vue'),
    meta: { roles: SPACES.admin },
  },
  {
    path: '/livreur',
    name: 'livreur',
    component: () => import('../layouts/LivreurLayout.vue'),
    meta: { roles: SPACES.livreur },
  },
  {
    path: '/marchand',
    name: 'marchand',
    component: () => import('../layouts/ClientLayout.vue'),
    meta: { roles: SPACES.marchand },
  },
  { path: '/:pathMatch(.*)*', redirect: '/' },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

router.beforeEach(async (to) => {
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

  // Utilisateur connecté : page de connexion, racine ou espace non autorisé → son espace
  if (to.meta.guest || to.path === '/' || (to.meta.roles && !to.meta.roles.includes(auth.role))) {
    return auth.homeRoute === to.path ? true : auth.homeRoute
  }

  return true
})

export default router
