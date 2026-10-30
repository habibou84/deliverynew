<template>
  <SettingsPage icon="📥" title="Reprise de l'ancienne application" intro="Récupérer les comptes et les commandes de l'ancienne application, les garder à jour chaque minute, puis y faire basculer tout le monde le jour choisi.">
    <p v-if="error" class="field-error">{{ error }}</p>
    <template v-if="data">
      <!-- État en une ligne -->
      <p class="rounded-lg bg-slate-100 px-3 py-2 text-sm">
        <template v-if="!data.orders.imported_at">Commandes pas encore reprises</template>
        <template v-else-if="data.orders.enabled">● Copie active<template v-if="data.orders.last_at">, dernière {{ since(data.orders.last_at) }}</template></template>
        <template v-else>⏸ Copie en pause</template>
        · {{ switchLine }}
        <span v-if="data.orders.last_error" class="block text-amber-700">⚠️ {{ data.orders.last_error }}</span>
      </p>

      <!-- À faire -->
      <section v-if="todos.length" class="card space-y-2 border-amber-200 bg-amber-50 p-4">
        <h2 class="font-semibold text-amber-900">À faire</h2>
        <RouterLink v-for="t in todos" :key="t.to + t.label" :to="t.to" class="flex items-center justify-between gap-3 rounded-lg bg-white px-3 py-2 text-sm font-medium text-amber-900 hover:bg-amber-100">
          <span>{{ t.label }}</span><span aria-hidden="true">›</span>
        </RouterLink>
      </section>

      <!-- Étapes -->
      <ol class="space-y-2">
        <li v-for="(s, i) in steps" :key="s.to">
          <RouterLink :to="s.to" :class="['card flex items-center gap-3 p-4 hover:bg-slate-50', s.state === 'done' ? 'py-3' : '']">
            <span :class="['flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm font-bold', s.state === 'done' ? 'bg-emerald-600 text-white' : s.state === 'running' ? 'bg-blue-600 text-white' : 'bg-slate-200 text-slate-700']">{{ s.state === 'done' ? '✓' : i + 1 }}</span>
            <span class="min-w-0 flex-1">
              <span class="block font-semibold">{{ s.title }}</span>
              <span class="block text-sm text-gray-600">{{ s.detail }}</span>
            </span>
            <span :class="['shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold', BADGES[s.state].class]">{{ BADGES[s.state].label }}</span>
          </RouterLink>
        </li>
      </ol>
    </template>
  </SettingsPage>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import http, { apiErrorMessage } from '../../../bootstrap/axios'
import SettingsPage from '../../../components/settings/SettingsPage.vue'
import { date, dateTime } from '../../../utils/format'

/**
 * Réglages › Reprise de l'ancienne application : où en est chaque étape, ce qui reste à faire.
 */
const BADGES = {
  done: { label: 'Fait', class: 'bg-emerald-100 text-emerald-800' },
  running: { label: 'En cours', class: 'bg-blue-100 text-blue-800' },
  todo: { label: 'À faire', class: 'bg-slate-100 text-slate-700' },
  partial: { label: 'À compléter', class: 'bg-amber-100 text-amber-800' },
}

const data = ref(null)
const error = ref('')

function since(iso) {
  const s = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 1000))
  return s < 90 ? `il y a ${s} s` : s < 5400 ? `il y a ${Math.round(s / 60)} min` : `le ${dateTime(iso)}`
}

const switchLine = computed(() => {
  const s = data.value.switch
  if (s.started) return `Bascule faite le ${date(s.switch_at)}`
  return s.switch_at ? `Bascule prévue le ${dateTime(s.switch_at)}` : 'Bascule pas encore prévue'
})

const todos = computed(() => {
  const t = data.value.todo
  const list = []
  if (t.deleted) list.push({ to: '/admin/reglages/reprise/supprimees', label: `${t.deleted} commande(s) supprimée(s) dans l'ancienne application à vérifier` })
  if (t.on_the_way) list.push({ to: `/admin/reglages/reprise/a-verifier?days=${t.on_the_way_days}`, label: `${t.on_the_way} course(s) en chemin depuis plus de ${t.on_the_way_days} jours à vérifier` })
  if (t.zones) list.push({ to: '/admin/reglages/reprise/zones', label: `${t.zones} zone(s) reprise(s) à rattacher aux lieux standard` })
  if (t.prices) list.push({ to: '/admin/reglages/reprise/tarifs', label: `${t.prices} prix de l'ancienne application à valider` })
  if (t.balances) list.push({ to: '/admin/reglages/reprise/controles', label: 'Soldes à comparer et valider' })
  if (t.cash) list.push({ to: '/admin/reglages/reprise/argent-livreurs', label: 'Argent chez les livreurs : point de départ à faire' })
  return list
})

const steps = computed(() => {
  const d = data.value
  const c = d.connect
  const plugged = [c.orders, c.accounts, c.switch].filter(Boolean).length
  const ordersRunning = ['running', 'stalled'].includes(d.orders.job)
  const left = todos.value.length
  return [
    {
      to: '/admin/reglages/reprise/brancher', title: 'Brancher l\'ancienne application',
      detail: `Lecture des commandes ${c.orders ? '✓' : '—'} · Envoi des comptes ${c.accounts ? '✓' : '—'} · Bascule ${c.switch ? '✓' : '—'}`,
      state: c.orders ? (c.switch ? 'done' : 'partial') : plugged ? 'partial' : 'todo',
    },
    {
      to: '/admin/reglages/reprise/comptes', title: 'Comptes et zones',
      detail: d.accounts.used_at ? `Dernier envoi reçu le ${dateTime(d.accounts.used_at)}` : 'E-commerçants, livreurs et zones envoyés par l\'ancienne application',
      state: d.accounts.used_at ? 'done' : 'todo',
    },
    {
      to: '/admin/reglages/reprise/commandes', title: 'Commandes',
      detail: ordersRunning ? 'Reprise en cours…' : d.orders.imported_at ? `${d.orders.count} course(s) reprise(s) · copie ${d.orders.enabled ? 'active' : 'en pause'}` : 'Simuler, reprendre, puis copie chaque minute',
      state: ordersRunning ? 'running' : d.orders.imported_at ? 'done' : 'todo',
    },
    {
      to: '/admin/reglages/reprise/controles', title: 'Contrôles et ménage',
      detail: d.todo.recheck_running ? 'Recomparaison en cours…' : left ? `${left} point(s) à faire` : 'Recomparaison, soldes, argent des livreurs, vieilles courses',
      state: d.todo.recheck_running ? 'running' : !d.orders.imported_at ? 'todo' : left ? 'partial' : 'done',
    },
    {
      to: '/admin/reglages/reprise/bascule', title: 'Bascule vers Jibiat',
      detail: switchLine.value,
      state: d.switch.started ? 'done' : d.switch.switch_at ? 'running' : 'todo',
    },
  ]
})

onMounted(async () => {
  try {
    data.value = (await http.get('/company/legacy-overview')).data.data
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
})
</script>
