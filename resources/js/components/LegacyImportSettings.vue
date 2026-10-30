<template>
  <!-- Étape « Brancher » : module et clé d'envoi des comptes -->
  <section v-if="state && part === 'connect'" class="card p-4 space-y-3">
    <div>
      <h2 class="font-semibold">Envoi des comptes et des zones</h2>
      <p class="text-sm text-gray-600">C'est l'ancienne application qui envoie ses zones, ses e-commerçants et ses livreurs, avec un bouton.</p>
    </div>

    <ol class="list-decimal space-y-1 pl-5 text-sm text-gray-700">
      <li>
        Copiez le module dans l'ancienne application, en suivant les explications en tête du fichier :
        <button type="button" class="text-blue-600" @click="downloadModule">télécharger JibiatMigrationController.php</button>.
      </li>
      <li>Créez une clé de reprise ci-dessous et collez-la, avec l'adresse de votre espace, dans sa page « Migration vers Jibiat ».</li>
    </ol>

    <!-- Clé affichée une seule fois -->
    <div v-if="key" class="space-y-2 rounded-lg bg-amber-50 p-3 text-sm">
      <p class="font-medium">Copiez la clé maintenant : elle ne sera plus affichée.</p>
      <div class="flex flex-wrap items-center gap-2">
        <span class="text-gray-600">Adresse :</span><code class="break-all">{{ origin }}</code>
        <button type="button" class="text-xs text-blue-600" @click="copy(origin)">Copier</button>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <span class="text-gray-600">Clé :</span><code class="break-all">{{ key }}</code>
        <button type="button" class="text-xs text-blue-600" @click="copy(key)">Copier</button>
      </div>
    </div>

    <p class="text-sm">
      <template v-if="state.active">✅ Clé active jusqu'au {{ dateTime(state.expires_at) }}.</template>
      <template v-else>Aucune clé active.</template>
    </p>

    <div class="flex flex-wrap gap-2">
      <button type="button" class="btn-primary" :disabled="busy" @click="createKey">{{ state.active ? 'Créer une nouvelle clé' : 'Créer une clé de reprise' }}</button>
      <button v-if="state.active" type="button" class="btn-secondary text-red-700" :disabled="busy" @click="revoke">Désactiver la clé</button>
    </div>
    <p class="text-xs text-gray-500">La clé est valable 7 jours ; une nouvelle clé remplace la précédente. Désactivez-la une fois les comptes repris.</p>
  </section>

  <!-- Étape « Comptes et zones » : ce qui est repris, et comment lancer l'envoi -->
  <section v-else-if="state" class="card p-4 space-y-3">
    <div>
      <h2 class="font-semibold">Comptes et zones</h2>
      <p class="text-sm text-gray-600">
        Les noms de vos zones (table « fees »), puis vos e-commerçants et livreurs arrivent depuis l'ancienne application ;
        chacun garde son numéro et son mot de passe. Les prix des zones ne sont pas repris : fixez vos tarifs dans Tarifs.
        Les comptes de l'équipe ne sont pas repris : recréez-les dans Utilisateurs.
      </p>
    </div>
    <ol class="list-decimal space-y-1 pl-5 text-sm text-gray-700">
      <li>Dans l'ancienne application, ouvrez la page « Migration vers Jibiat ».</li>
      <li>Cliquez sur « Simuler », vérifiez le résultat, puis sur « Envoyer ». Relancer ne crée pas de doublon.</li>
    </ol>
    <p class="text-sm">
      <template v-if="state.used_at">✅ Dernier envoi reçu le {{ dateTime(state.used_at) }}.</template>
      <template v-else>Aucun envoi reçu pour l'instant.</template>
    </p>
    <p v-if="!state.active" class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">
      Pas de clé de reprise active : créez-la d'abord dans
      <RouterLink to="/admin/reglages/reprise/brancher" class="font-semibold underline">Brancher l'ancienne application</RouterLink>.
    </p>
    <p class="text-xs text-gray-500">Les zones sont envoyées en premier : la commune de chaque e-commerçant sert à choisir sa zone de ramassage.</p>
  </section>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import http, { apiErrorMessage } from '../bootstrap/axios'
import { useToastStore } from '../stores/toasts'
import { dateTime } from '../utils/format'

/**
 * Réglages › Reprise › comptes et zones envoyés par l'ancienne application (Laravel 5.8). part « connect » : module et
 * clé à coller dans son bouton d'envoi (page Brancher) ; sinon : ce qui est repris et le dernier envoi.
 */
defineProps({ part: { type: String, default: 'status' } })
const toasts = useToastStore()
const state = ref(null)
const key = ref('')
const busy = ref(false)
const origin = window.location.origin

async function createKey() {
  if (state.value.active && !window.confirm('Créer une nouvelle clé ? L\'ancienne ne fonctionnera plus.')) return
  busy.value = true
  try {
    const { data } = await http.post('/company/legacy-import/key')
    key.value = data.data.key
    state.value = data.data
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

async function revoke() {
  busy.value = true
  try {
    state.value = (await http.delete('/company/legacy-import/key')).data.data
    key.value = ''
    toasts.success('Clé désactivée.')
  } finally {
    busy.value = false
  }
}

async function downloadModule() {
  const { data } = await http.get('/company/legacy-import/module', { responseType: 'blob' })
  const url = URL.createObjectURL(data)
  const link = Object.assign(document.createElement('a'), { href: url, download: 'JibiatMigrationController.php' })
  link.click()
  URL.revokeObjectURL(url)
}

async function copy(text) {
  try {
    await navigator.clipboard.writeText(text)
    toasts.success('Copié.')
  } catch {
    // presse-papiers indisponible
  }
}

onMounted(async () => {
  try {
    state.value = (await http.get('/company/legacy-import')).data.data
  } catch {
    state.value = null
  }
})
</script>
