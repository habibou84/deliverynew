<template>
  <div class="space-y-4">
    <div>
      <h1 class="text-xl font-bold">Journal des actions</h1>
      <p class="text-sm text-gray-600">
        Chaque modification faite par l'équipe de la console (console, fiches entreprises, sessions d'assistance).
        Les mots de passe, codes et jetons ne sont jamais enregistrés.
      </p>
    </div>

    <div class="flex flex-wrap gap-2">
      <select v-model="filters.action" class="input w-auto" aria-label="Type d'action" @change="load(1)">
        <option value="">Toutes les actions</option>
        <option v-for="g in GROUPS" :key="g.value" :value="g.value">{{ g.label }}</option>
      </select>
      <input v-model="filters.from" type="date" class="input w-auto" aria-label="Depuis le" @change="load(1)">
      <input v-model="filters.to" type="date" class="input w-auto" aria-label="Jusqu'au" @change="load(1)">
      <button v-if="filters.company_id" type="button" class="btn-secondary" @click="filters.company_id = ''; load(1)">✕ {{ companyName || 'Entreprise' }}</button>
    </div>

    <p v-if="loading" class="text-gray-500">Chargement…</p>
    <p v-else-if="!logs.length" class="card p-4 text-gray-500">Aucune action pour ces filtres.</p>

    <ul v-else class="card divide-y">
      <li v-for="log in logs" :key="log.id" class="p-3 text-sm">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
          <p>
            <span class="font-medium">{{ label(log.action) }}</span>
            <span v-if="log.status >= 400" class="ml-1 rounded bg-red-100 px-1.5 text-xs text-red-700">refusé ({{ log.status }})</span>
            <span v-if="log.data?.support_as" class="ml-1 rounded bg-amber-100 px-1.5 text-xs text-amber-800">assistance, en tant que {{ log.data.support_as.name }}</span>
          </p>
          <span class="text-xs text-gray-500">{{ dateTime(log.created_at) }}</span>
        </div>
        <p class="text-gray-600">
          {{ log.user?.name || 'Compte supprimé' }}
          <template v-if="log.company"> · <button type="button" class="text-blue-600 hover:underline" @click="byCompany(log.company)">{{ log.company.name }}</button></template>
          · <span class="text-xs">{{ log.ip }}</span>
        </p>
        <details v-if="hasDetails(log)" class="mt-1">
          <summary class="cursor-pointer text-xs text-gray-500">Détail</summary>
          <pre class="mt-1 overflow-x-auto rounded bg-slate-50 p-2 text-xs">{{ JSON.stringify(log.data, null, 2) }}</pre>
        </details>
      </li>
    </ul>

    <div v-if="meta.last_page > 1" class="flex items-center justify-center gap-3 text-sm">
      <button class="btn-secondary" :disabled="meta.current_page <= 1" @click="load(meta.current_page - 1)">← Plus récentes</button>
      <span>Page {{ meta.current_page }} / {{ meta.last_page }}</span>
      <button class="btn-secondary" :disabled="meta.current_page >= meta.last_page" @click="load(meta.current_page + 1)">Plus anciennes →</button>
    </div>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { useToastStore } from '../../stores/toasts'
import { dateTime } from '../../utils/format'

// Libellés des actions (nom de la route) ; une action inconnue garde son nom technique
const LABELS = {
  'auth.login': 'Connexion',
  'auth.logout': 'Déconnexion',
  'auth.two-factor': 'Connexion avec le code reçu',
  'auth.password': 'Mot de passe changé',
  'console.account.update': 'Mon compte modifié (nom, téléphone, e-mail)',
  'console.account.devices.forget': 'Appareils retenus oubliés',
  'console.search': 'Recherche dans la console',
  'console.team.invite': 'Personne invitée dans l\'équipe de la console',
  'console.team.reinvite': 'Nouveau lien d\'invitation (équipe de la console)',
  'console.team.update': 'Rôle ou état d\'un membre de la console changé',
  'console.not-me': '« Ce n\'était pas moi » : compte sécurisé',
  'console.gmail.connect': 'Connexion de Gmail demandée',
  'console.gmail.disconnect': 'Gmail déconnecté',
  'companies.store': 'Entreprise créée',
  'companies.update': 'Fiche entreprise modifiée',
  'console.companies.support': 'Session d\'assistance ouverte',
  'console.companies.support.close': 'Session d\'assistance fermée',
  'console.companies.billing-cycle': 'Rythme de facturation changé',
  'console.plans.store': 'Formule créée',
  'console.plans.suggested': 'Formules proposées créées',
  'console.plans.update': 'Formule modifiée',
  'console.invoices.generate': 'Factures générées',
  'console.invoices.pay': 'Paiement de facture enregistré',
  'console.invoices.void': 'Facture annulée',
  'console.settings.update': 'Mentions des factures ou inscription en ligne modifiées',
  'console.wave.update': 'Réglages Wave modifiés (lien, validateur)',
  'console.wave.qr': 'QR code Wave remplacé',
  'console.wave.qr.remove': 'QR code Wave retiré',
  'console.wave.mail': 'E-mail des alertes modifié',
  'console.wave.test-alert': 'Alerte d\'essai envoyée',
  'console.wave.decide': 'Paiement Wave validé ou refusé',
  'console.whatsapp.connect': 'WhatsApp de Jibiat : QR code demandé',
  'console.whatsapp.disconnect': 'WhatsApp de Jibiat déconnecté',
  'console.whatsapp.official': 'WhatsApp officiel de Jibiat réglé',
  'console.whatsapp.official.verify': 'WhatsApp officiel vérifié',
  'console.whatsapp.official.remove': 'WhatsApp officiel retiré',
  'console.whatsapp.sms': 'SMS en secours activés ou coupés',
  'console.whatsapp.open': 'Conversation WhatsApp ouverte',
  'console.whatsapp.reply': 'Message WhatsApp envoyé',
}
const GROUPS = [
  { value: 'companies', label: 'Entreprises' },
  { value: 'console.companies.support', label: 'Assistance' },
  { value: 'console.plans', label: 'Formules' },
  { value: 'console.invoices', label: 'Factures' },
  { value: 'console.wave', label: 'Paiements Wave' },
  { value: 'console.whatsapp', label: 'WhatsApp et SMS' },
  { value: 'auth', label: 'Connexions' },
  { value: 'console.team', label: 'Équipe de la console' },
  { value: 'console.search', label: 'Recherches' },
]

const route = useRoute()
const toasts = useToastStore()
const logs = ref([])
const meta = ref({ current_page: 1, last_page: 1 })
const loading = ref(false)
const companyName = ref('')
const filters = reactive({ action: '', from: '', to: '', company_id: route.query.company || '' })

function label(action) {
  return LABELS[action] ?? action
}

function hasDetails(log) {
  const input = log.data?.input ?? {}
  return Object.keys(input).length > 0 || Object.keys(log.data?.targets ?? {}).length > 0
}

function byCompany(company) {
  filters.company_id = company.id
  companyName.value = company.name
  load(1)
}

async function load(page = 1) {
  loading.value = true
  try {
    const params = Object.fromEntries(Object.entries({ ...filters, page }).filter(([, v]) => v !== '' && v !== null))
    const { data } = await http.get('/console/audit', { params })
    logs.value = data.data
    meta.value = data.meta
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    loading.value = false
  }
}

onMounted(() => load())
</script>
