import { onBeforeUnmount } from 'vue'

/**
 * Rafraîchissements automatiques en pause quand l'écran est caché (autre application, téléphone en veille) :
 * moins de batterie et de forfait. Au retour sur l'écran, `fn` est appelée tout de suite.
 * À appeler dans le setup du composant.
 */
export function refreshWhenVisible(fn) {
  const onChange = () => {
    if (!document.hidden) fn()
  }
  document.addEventListener('visibilitychange', onChange)
  onBeforeUnmount(() => document.removeEventListener('visibilitychange', onChange))
}

/** Vrai quand l'écran est visible (à tester dans le rappel d'un setInterval) */
export const visible = () => !document.hidden
