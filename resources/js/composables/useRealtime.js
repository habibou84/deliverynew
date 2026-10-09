import { onBeforeUnmount, ref } from 'vue'
import { useEcho } from '../bootstrap/echo'
import { useAuthStore } from '../stores/auth'
import { useNotificationStore } from '../stores/notifications'
import { useToastStore } from '../stores/toasts'
import { useFieldReportStore } from '../stores/fieldReports'
import { desktopNotify, playAlertSound } from './useAlertSound'
import router from '../router'

// Compteur global incrémenté à chaque changement de course reçu en temps réel :
// les écrans le surveillent pour se rafraîchir.
export const orderChanges = ref(0)
export const lastOrderChange = ref(null)

/**
 * Abonne l'utilisateur connecté à ses canaux temps réel :
 * - notifications personnelles (cloche + toast) ;
 * - courses de l'entreprise (personnel) ou du marchand.
 */
export function useRealtime() {
  const auth = useAuthStore()
  const notifications = useNotificationStore()
  const toasts = useToastStore()
  const echo = useEcho()
  const channels = []

  if (!echo || !auth.user) return

  const userChannel = `App.Models.User.${auth.user.id}`
  const fieldReports = useFieldReportStore()

  echo.private(userChannel).notification((n) => {
    notifications.receive(n)

    // Remontée terrain d'un livreur (note, problème) : son, notification du bureau, toast prolongé
    if (n.field) {
      const alert = n.severity === 'alert'
      playAlertSound(n.severity)
      desktopNotify(n.title, n.body, () => router.push(`${auth.homeRoute}/courses/${n.order_id}`))
      fieldReports.received()
      toasts.push(n.body, alert ? 'error' : 'info', { title: `${alert ? '⚠️' : '📝'} ${n.title}`, to: n.order_id, timeout: alert ? 20000 : 10000 })
      return
    }

    toasts.push(n.body, n.kind === 'incident' ? 'error' : 'info', { title: n.title, to: n.order_id })
  })
  channels.push(userChannel)

  const orderChannel = auth.user.merchant_id
    ? `merchant.${auth.user.merchant_id}`
    : (auth.user.company_id && auth.role !== 'courier' ? `company.${auth.user.company_id}` : null)

  if (orderChannel) {
    echo.private(orderChannel).listen('.order.changed', (payload) => {
      lastOrderChange.value = payload
      orderChanges.value++
    })
    channels.push(orderChannel)
  }

  onBeforeUnmount(() => channels.forEach((c) => echo.leave(c)))
}
