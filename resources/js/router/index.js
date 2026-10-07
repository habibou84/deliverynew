import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'

import Login from '../views/Login.vue'
import AdminLayout from '../layouts/AdminLayout.vue'
import LivreurLayout from '../layouts/LivreurLayout.vue'
import ClientLayout from '../layouts/ClientLayout.vue'

const routes = [
  {
    path: '/',
    redirect: '/login'
  },

  {
    path: '/login',
    name: 'login',
    component: Login,
    meta: { guest: true }
  },

  {
    path: '/admin',
    component: AdminLayout,
    meta: { requiresAuth: true, role: 'admin' }
  },

  {
    path: '/livreur',
    component: LivreurLayout,
    meta: { requiresAuth: true, role: 'livreur' }
  },

  {
    path: '/client',
    component: ClientLayout,
    meta: { requiresAuth: true, role: 'client' }
  }
]

const router = createRouter({
  history: createWebHistory(),
  routes
})

/*
|--------------------------------------------------------------------------
| Guard global
|--------------------------------------------------------------------------
*/
router.beforeEach(async (to, from, next) => {
  const auth = useAuthStore()

  // Page publique (login)
  if (to.meta.guest) {
    if (auth.token) {
      // déjà connecté → redirection selon rôle
      if (auth.user) {
        if (auth.user.role === 'admin') return next('/admin')
        if (auth.user.role === 'livreur') return next('/livreur')
        if (auth.user.role === 'client') return next('/client')
      }
    }
    return next()
  }

  // Page protégée
  if (to.meta.requiresAuth) {
    if (!auth.token) {
      return next('/login')
    }

    // Si l'utilisateur n’est pas encore chargé
    if (!auth.user) {
      try {
        await auth.fetchUser()
      } catch {
        return next('/login')
      }
    }

    // Vérification du rôle
    if (to.meta.role && auth.user.role !== to.meta.role) {
      // tentative d’accès interdit → on renvoie vers son espace
      if (auth.user.role === 'admin') return next('/admin')
      if (auth.user.role === 'livreur') return next('/livreur')
      if (auth.user.role === 'client') return next('/client')
    }
  }

  next()
})

export default router
