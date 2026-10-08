import { onBeforeUnmount, ref } from 'vue'
import { useEcho } from '../bootstrap/echo'
import { useAuthStore } from '../stores/auth'
import { useNotificationStore } from '../stores/notifications'
import { useToastStore } from '../stores/toasts'

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
  echo.private(userChannel).notification((n) => {
    notifications.receive(n)
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
