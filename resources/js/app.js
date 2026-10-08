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
