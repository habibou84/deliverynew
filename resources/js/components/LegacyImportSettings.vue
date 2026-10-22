<template>
  <section v-if="state" class="card p-4 space-y-3">
    <div>
      <h2 class="font-semibold">Reprise de l'ancienne application</h2>
      <p class="text-sm text-gray-600">
        Vos e-commerçants et livreurs arrivent depuis l'ancienne application avec un bouton, en gardant leur numéro
        et leur mot de passe. Les commandes ne sont pas reprises, ni les comptes de l'équipe : recréez-les dans Utilisateurs.
      </p>
    </div>

    <ol class="list-decimal space-y-1 pl-5 text-sm text-gray-700">
      <li>
        Copiez le module dans l'ancienne application, en suivant les explications en tête du fichier :
        <button type="button" class="text-blue-600" @click="downloadModule">télécharger JibiatMigrationController.php</button>.
      </li>
      <li>Créez une clé de reprise ci-dessous et collez-la, avec l'adresse de votre espace, dans sa page « Migration vers Jibiat ».</li>
      <li>Cliquez sur « Simuler », vérifiez le résultat, puis sur « Envoyer ». Relancer ne crée pas de doublon.</li>
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
      <span v-if="state.used_at" class="text-gray-500"> Dernier envoi reçu le {{ dateTime(state.used_at) }}.</span>
    </p>

    <div class="flex flex-wrap gap-2">
      <button type="button" class="btn-primary" :disabled="busy" @click="createKey">{{ state.active ? 'Créer une nouvelle clé' : 'Créer une clé de reprise' }}</button>
      <button v-if="state.active" type="button" class="btn-secondary text-red-700" :disabled="busy" @click="revoke">Désactiver la clé</button>
    </div>
    <!-- Zones : seulement les noms, depuis l'export de la table « fees » -->
    <div class="space-y-2 rounded-lg border p-3">
      <p class="text-sm font-medium">Zones de l'ancienne application</p>
      <p class="text-xs text-gray-600">
        Exportez la table « fees » depuis phpMyAdmin (format SQL) et envoyez le fichier : le nom de chaque lieu est ajouté à vos zones.
        Les prix ne sont pas repris. Une zone déjà présente n'est pas recréée.
      </p>
      <div class="flex flex-wrap items-center gap-2">
        <input ref="zonesFile" type="file" accept=".sql,text/plain" class="text-sm" @change="zonesReport = null">
        <button type="button" class="btn-secondary" :disabled="busy" @click="importZones(true)">Simuler</button>
        <button type="button" class="btn-primary" :disabled="busy || !zonesReport?.dry" @click="importZones(false)">Ajouter les zones</button>
      </div>
      <div v-if="zonesReport" class="text-sm space-y-1">
        <p :class="zonesReport.dry ? 'text-gray-700' : 'text-emerald-700 font-medium'">
          {{ zonesReport.dry ? 'Simulation : ' : 'Fait : ' }}{{ zonesReport.counts.zones_created }} zone(s) {{ zonesReport.dry ? 'à ajouter' : 'ajoutée(s)' }},
          {{ zonesReport.counts.zones_existing }} déjà présente(s).
        </p>
        <p class="text-xs text-gray-600">{{ zonesReport.zones.filter((z) => z.status === 'created').map((z) => z.name).join(', ') || 'Aucune nouvelle zone.' }}</p>
        <p v-for="s in zonesReport.skipped" :key="s.id" class="text-xs text-gray-500">Ignorée : {{ s.name }} ({{ s.reason }})</p>
      </div>
    </div>

    <p class="text-xs text-gray-500">
      La clé est valable 7 jours ; une nouvelle clé remplace la précédente. Désactivez-la une fois la reprise terminée.
      Créez d'abord vos zones : la commune de chaque e-commerçant sert à choisir sa zone de ramassage.
    </p>
  </section>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import http, { apiErrorMessage } from '../bootstrap/axios'
import { useToastStore } from '../stores/toasts'
import { dateTime } from '../utils/format'

/**
 * Paramètres › Reprise de l'ancienne application (Laravel 5.8) : clé à coller dans son bouton d'envoi.
 */
const toasts = useToastStore()
const state = ref(null)
const key = ref('')
const busy = ref(false)
const origin = window.location.origin
const zonesFile = ref(null)
const zonesReport = ref(null)

// Simulation d'abord, puis ajout des zones (même fichier)
async function importZones(dryRun) {
  const file = zonesFile.value?.files?.[0]
  if (!file) {
    toasts.error('Choisissez le fichier SQL de la table « fees ».')
    return
  }
  busy.value = true
  try {
    const form = new FormData()
    form.append('file', file)
    form.append('dry_run', dryRun ? '1' : '0')
    const { data } = await http.post('/company/legacy-import/zones', form)
    zonesReport.value = { ...data.data, dry: dryRun }
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

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
