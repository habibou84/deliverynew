<template>
  <div class="max-w-5xl space-y-4">
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-xl font-bold">Messages prédéfinis</h1>
        <p class="text-sm text-slate-600">Les messages envoyés tout seuls à chaque étape. Meta doit approuver chaque modèle avant son premier envoi par WhatsApp.</p>
      </div>
      <div v-if="s" class="flex flex-wrap gap-2">
        <button type="button" class="btn-secondary" :disabled="syncing" @click="sync">Vérifier l'approbation chez Meta</button>
        <button v-if="s.mode === 'live' && toSubmit" type="button" class="btn-primary" :disabled="submitting" @click="submitTemplates">
          {{ submitting ? 'Envoi à Meta…' : `Soumettre ${toSubmit > 1 ? `les ${toSubmit} modèles` : 'le modèle'} à Meta` }}
        </button>
      </div>
    </div>

    <p v-if="s && s.mode !== 'live'" class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">
      WhatsApp n'est pas connecté : ces messages ne partent pas encore.
      <RouterLink to="/admin/whatsapp" class="font-semibold underline">Connecter WhatsApp</RouterLink>
    </p>

    <!-- Onglets : à qui s'adressent les messages -->
    <div role="tablist" aria-label="Destinataires" class="inline-flex flex-wrap gap-1 rounded-xl bg-slate-200 p-1">
      <button
        v-for="t in tabs" :key="t.value" type="button" role="tab" :aria-selected="tab === t.value"
        :class="['min-h-10 rounded-lg px-4 text-sm font-semibold', tab === t.value ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-700 hover:bg-white/60']"
        @click="choose(t.value)"
      >
        {{ t.label }}<span v-if="s" class="ml-1 font-normal text-slate-600">· {{ countFor(t.value) }}</span>
      </button>
    </div>

    <p class="text-sm text-slate-600">{{ INTROS[tab] }}</p>

    <!-- E-commerçants : par étape, application et WhatsApp -->
    <SettingsMerchantMessages v-if="tab === 'merchant'" embedded />

    <p v-if="loadError" class="field-error">{{ loadError }}</p>
    <p v-else-if="!s" class="card p-6 text-center text-slate-600">Chargement…</p>

    <section v-if="s" class="space-y-3">
      <h2 v-if="tab === 'merchant'" class="pt-2 font-semibold">Texte des messages WhatsApp</h2>
      <article v-for="t in visible" :key="t.name" class="card space-y-2 p-4">
        <div class="flex flex-wrap items-center gap-2">
          <h3 class="font-semibold">{{ t.title }}</h3>
          <span :class="['rounded-full px-2 py-0.5 text-xs font-medium', TEMPLATE_STATUS[t.status]?.[1] || 'bg-slate-100 text-slate-700']">
            {{ TEMPLATE_STATUS[t.status]?.[0] || t.status }}
          </span>
        </div>
        <p class="text-sm text-slate-600">{{ t.description }}</p>
        <div class="flex items-start gap-2">
          <p class="flex-1 rounded-lg border bg-slate-50 p-3 text-sm">{{ preview(t) }}</p>
          <button type="button" class="btn-secondary text-xs" @click="copy(t.body)">Copier</button>
        </div>
        <p class="text-xs text-slate-600">Modèle Meta : {{ t.name }}</p>
        <p v-if="t.rejected_reason" class="text-xs text-red-700">Motif du refus : {{ t.rejected_reason }}</p>
      </article>
    </section>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { useToastStore } from '../../stores/toasts'
import SettingsMerchantMessages from './settings/SettingsMerchantMessages.vue'

/**
 * Messages › Messages prédéfinis : les modèles WhatsApp rangés par destinataire (clients, e-commerçants,
 * livreurs, équipe), leur état chez Meta ; pour les e-commerçants, le choix application / WhatsApp par étape.
 */
const TEMPLATE_STATUS = {
  approved: ['Approuvé par Meta', 'bg-emerald-100 text-emerald-800'],
  pending: ['En attente chez Meta', 'bg-amber-100 text-amber-800'],
  rejected: ['Refusé par Meta', 'bg-red-100 text-red-800'],
  paused: ['En pause', 'bg-amber-100 text-amber-800'],
  disabled: ['Désactivé', 'bg-red-100 text-red-800'],
  unknown: ['À soumettre à Meta', 'bg-slate-100 text-slate-700'],
}
const tabs = [
  { value: 'client', label: 'Clients' },
  { value: 'merchant', label: 'E-commerçants' },
  { value: 'courier', label: 'Livreurs' },
  { value: 'team', label: 'Équipe' },
]
const INTROS = {
  client: 'Envoyés aux destinataires des colis : annonce du livreur avec le code de livraison, colis expédié, avis après la livraison.',
  merchant: 'Envoyés aux e-commerçants à chaque étape de leurs courses.',
  courier: 'Envoyés aux livreurs : reçu de leur versement, rappel quand ils gardent l\'argent trop longtemps.',
  team: 'Envoyés au gérant et aux responsables : clôture de la caisse, point de la semaine.',
}

const route = useRoute()
const router = useRouter()
const toasts = useToastStore()
const tab = ref(tabs.some((t) => t.value === route.query.onglet) ? route.query.onglet : 'client')
const s = ref(null)
const loadError = ref('')
const syncing = ref(false)
const submitting = ref(false)

const visible = computed(() => (s.value?.templates || []).filter((t) => t.audience === tab.value))
const toSubmit = computed(() => (s.value?.templates || []).filter((t) => ['unknown', 'rejected'].includes(t.status)).length)
const countFor = (audience) => (s.value?.templates || []).filter((t) => t.audience === audience).length

function choose(value) {
  tab.value = value
  router.replace({ query: { ...route.query, onglet: value } })
}

// Texte du modèle avec ses exemples à la place de {{1}}, {{2}}…
function preview(t) {
  return t.body.replace(/\{\{(\d+)\}\}/g, (m, n) => t.example?.[Number(n) - 1] ?? m)
}

async function load() {
  try {
    s.value = (await http.get('/whatsapp/settings')).data.data
  } catch (e) {
    loadError.value = apiErrorMessage(e)
  }
}

async function sync() {
  syncing.value = true
  try {
    s.value = (await http.post('/whatsapp/templates/sync')).data.data
    toasts.success('État des modèles mis à jour.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    syncing.value = false
  }
}

async function submitTemplates() {
  submitting.value = true
  try {
    const { data } = await http.post('/whatsapp/templates/submit')
    s.value = data.data
    const errors = Object.entries(data.meta.errors || {})
    if (errors.length) toasts.error(`${errors.length} modèle(s) refusé(s) à l'envoi : ${errors.map(([name, error]) => `${name} (${error})`).join(' ; ')}`)
    else toasts.success(`${data.meta.submitted} modèle(s) envoyé(s) à Meta pour validation.`)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    submitting.value = false
  }
}

async function copy(text) {
  try {
    await navigator.clipboard.writeText(text)
    toasts.success('Copié.')
  } catch {
    toasts.error('Copie impossible : sélectionnez le texte à la main.')
  }
}

onMounted(load)
</script>
