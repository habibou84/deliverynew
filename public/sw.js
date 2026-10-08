/*
 * Service worker des applications e-commerçant et livreur.
 * - Pages : réseau d'abord, sinon la dernière version de l'application (hors ligne).
 * - Fichiers compilés (/build, noms avec empreinte) : cache d'abord.
 * - Icônes et manifestes : cache puis mise à jour en arrière-plan.
 * - API, temps réel : jamais mis en cache (données toujours fraîches).
 */
const VERSION = 'v1'
const SHELL = `shell-${VERSION}`
const ASSETS = `assets-${VERSION}`
const SHELL_KEY = '/__app-shell'

self.addEventListener('install', () => self.skipWaiting())

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const keys = await caches.keys()
    await Promise.all(keys.filter((k) => ![SHELL, ASSETS].includes(k)).map((k) => caches.delete(k)))
    await self.clients.claim()
  })())
})

self.addEventListener('fetch', (event) => {
  const { request } = event
  const url = new URL(request.url)

  if (request.method !== 'GET' || url.origin !== self.location.origin) return
  if (url.pathname.startsWith('/api/') || url.pathname.startsWith('/broadcasting')) return

  // Navigation dans l'application (toutes les pages servent le même HTML)
  if (request.mode === 'navigate') {
    if (url.pathname.startsWith('/admin')) return
    event.respondWith((async () => {
      try {
        const response = await fetch(request)
        if (response.ok) {
          const cache = await caches.open(SHELL)
          await cache.put(SHELL_KEY, response.clone())
        }
        return response
      } catch {
        const cached = await caches.match(SHELL_KEY)
        return cached || new Response('<h1>Hors ligne</h1><p>Reconnectez-vous à Internet.</p>', {
          headers: { 'Content-Type': 'text/html; charset=utf-8' },
        })
      }
    })())
    return
  }

  if (url.pathname.startsWith('/build/')) {
    event.respondWith((async () => {
      const cached = await caches.match(request)
      if (cached) return cached
      const response = await fetch(request)
      if (response.ok) (await caches.open(ASSETS)).put(request, response.clone())
      return response
    })())
    return
  }

  if (url.pathname.startsWith('/icons/') || url.pathname.startsWith('/manifest/')) {
    event.respondWith((async () => {
      const cache = await caches.open(ASSETS)
      const cached = await cache.match(request)
      const network = fetch(request).then((response) => {
        if (response.ok) cache.put(request, response.clone())
        return response
      }).catch(() => cached)
      return cached || network
    })())
  }
})
