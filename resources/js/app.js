// En premier : le jeton d'une session d'assistance est retiré de l'adresse avant le routeur
import './bootstrap/supportSession'
import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import { applyAppTheme, setupPwa } from './composables/usePwa'

setupPwa()
router.afterEach((to) => applyAppTheme(to.path))

createApp(App)
  .use(createPinia())
  .use(router)
  .mount('#app')
