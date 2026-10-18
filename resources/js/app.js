// En premier : le jeton d'une session d'assistance est retiré de l'adresse avant le routeur
import './bootstrap/supportSession'
import { createApp, watch } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import { applyAppTheme, setupPwa } from './composables/usePwa'
import { branding } from './composables/useBranding'

setupPwa()
router.afterEach((to) => applyAppTheme(to.path))
// Apparence chargée ou modifiée (Paramètres › Apparence) : barre du téléphone mise à jour
watch(() => branding.appearance, () => applyAppTheme(router.currentRoute.value.path), { deep: true })

createApp(App)
  .use(createPinia())
  .use(router)
  .mount('#app')
