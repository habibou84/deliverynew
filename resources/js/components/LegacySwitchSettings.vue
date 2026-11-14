<template>
  <section v-if="state" class="card p-4 space-y-3">
    <div>
      <h2 class="font-semibold">{{ part === 'connect' ? 'Module de bascule' : 'Date de la bascule' }}</h2>
      <p v-if="part === 'connect'" class="text-sm text-slate-600">Le jour de la bascule, il envoie sur Jibiat, déjà connectés, les comptes qui ouvrent l'ancienne application.</p>
      <p v-else class="text-sm text-slate-600">
        Le jour choisi, vos e-commerçants et vos livreurs qui ouvrent l'ancienne application arrivent directement sur Jibiat,
        déjà connectés à leur espace : pas de nouveau compte, pas de mot de passe à retaper. Avant cette date, rien ne change,
        sauf le bandeau ci-dessous si vous l'activez.
        Un compte créé dans l'ancienne application après la reprise est repris au moment de la bascule.
      </p>
    </div>

    <template v-if="part === 'connect'">
    <ol class="list-decimal space-y-1 pl-5 text-sm text-slate-700">
      <li>
        Copiez le module dans l'ancienne application, en suivant les explications en tête du fichier :
        <button type="button" class="text-blue-600" @click="downloadModule">télécharger JibiatSwitch.php</button>.
      </li>
      <li>Créez une clé de bascule et mettez-la, avec l'adresse de votre espace, dans le fichier .env de l'ancienne application.</li>
    </ol>

    <!-- Clé affichée une seule fois -->
    <div v-if="key" class="space-y-2 rounded-lg bg-amber-50 p-3 text-sm">
      <p class="font-medium">Copiez ces deux lignes maintenant : la clé ne sera plus affichée.</p>
      <pre class="whitespace-pre-wrap break-all rounded bg-white p-2 text-xs">{{ envLines }}</pre>
      <button type="button" class="text-xs text-blue-600" @click="copy(envLines)">Copier</button>
    </div>

    <div class="flex flex-wrap items-center gap-2 text-sm">
      <span>{{ state.key_active ? '✅ Clé de bascule active.' : 'Aucune clé de bascule.' }}</span>
      <button type="button" class="btn-secondary" :disabled="busy" @click="createKey">{{ state.key_active ? 'Nouvelle clé' : 'Créer la clé' }}</button>
      <button v-if="state.key_active" type="button" class="btn-secondary text-red-700" :disabled="busy" @click="revoke">Désactiver</button>
    </div>

    <p v-if="state.module?.at" :class="['text-sm', state.module.version >= state.module.latest ? 'text-emerald-700' : 'text-amber-700']">
      <template v-if="state.module.version >= state.module.latest">✅ Module à jour (version {{ state.module.version }}), vu le {{ dateTime(state.module.at) }}.</template>
      <template v-else>⚠️ Le module installé est ancien (version {{ state.module.version }}, vu le {{ dateTime(state.module.at) }}) : il ne sait pas afficher le bandeau. Téléchargez la nouvelle version et remplacez le fichier.</template>
    </p>
    <p v-else-if="state.key_active" class="text-xs text-slate-600">Le module ne s'est pas encore manifesté : il le fait à la première page ouverte dans l'ancienne application.</p>

    </template>

    <template v-else>
    <p class="text-sm text-slate-600">Choisissez le jour et l'heure de la bascule. Vous pouvez la déplacer ou l'annuler tant qu'elle n'a pas commencé.</p>
    <div class="space-y-2 rounded-lg border p-3">
      <p v-if="state.started" class="text-sm font-medium text-emerald-700">
        🚀 Bascule commencée le {{ dateTime(state.switch_at) }} : les comptes qui ouvrent l'ancienne application arrivent sur Jibiat.
      </p>
      <p v-if="state.started && state.ends_at" class="text-xs text-slate-600">
        Par sécurité, la bascule s'arrête le {{ dateTime(state.ends_at) }} : ensuite, la clé ne sert plus et chacun se connecte directement sur Jibiat. Retirez alors le module de l'ancienne application.
      </p>
      <p v-else-if="state.switch_at" class="text-sm font-medium">📅 Bascule prévue le {{ dateTime(state.switch_at) }}.</p>
      <p v-else class="text-sm text-slate-600">Aucune bascule prévue.</p>

      <div class="flex flex-wrap items-end gap-2">
        <div>
          <label class="label" for="switch-at">Jour et heure de la bascule</label>
          <input id="switch-at" v-model="when" type="datetime-local" class="input">
        </div>
        <button type="button" class="btn-primary" :disabled="busy || !when" @click="save(new Date(when).toISOString())">Programmer</button>
        <button v-if="!state.started" type="button" class="btn-secondary" :disabled="busy" @click="now">Basculer maintenant</button>
        <button v-if="state.switch_at" type="button" class="btn-secondary text-red-700" :disabled="busy" @click="save(null)">Annuler la bascule</button>
      </div>
      <p v-if="!state.key_active" class="text-xs text-amber-700">
        Sans clé de bascule installée dans l'ancienne application, personne n'est envoyé sur Jibiat : créez-la dans
        <RouterLink to="/admin/reglages/reprise/brancher" class="font-semibold underline">Brancher l'ancienne application</RouterLink>.
      </p>
    </div>

    <!-- Avant la bascule : bandeau dans l'ancienne application -->
    <div class="space-y-2 rounded-lg border p-3">
      <h3 class="text-sm font-semibold">Montrer Jibiat dans l'ancienne application</h3>
      <p class="text-xs text-slate-600">
        Un bandeau s'affiche en bas des pages de l'ancienne application. L'équipe peut ouvrir Jibiat et travailler sur
        les deux en même temps. Les e-commerçants et les livreurs ouvrent leur espace Jibiat déjà connectés, dans un
        nouvel onglet, sans quitter l'ancienne application. Le module doit être en version {{ state.module?.latest || 2 }}.
      </p>
      <label class="flex items-center gap-2 text-sm"><input v-model="preview.team" type="checkbox"> Équipe : bouton « Ouvrir Jibiat »</label>
      <label class="flex items-center gap-2 text-sm"><input v-model="preview.merchants" type="checkbox"> E-commerçants : bouton « Ouvrir la nouvelle application »</label>
      <label class="flex items-center gap-2 text-sm"><input v-model="preview.couriers" type="checkbox"> Livreurs : bouton « Ouvrir la nouvelle application »</label>
      <div>
        <label class="label" for="preview-message">Message pour les e-commerçants et les livreurs (facultatif)</label>
        <input id="preview-message" v-model="preview.message" type="text" maxlength="200" class="input w-full" placeholder="Découvrez notre nouvelle application : vos courses, votre argent et vos notifications au même endroit.">
      </div>
      <p v-if="state.started" class="text-xs text-slate-600">La bascule a commencé : les e-commerçants et les livreurs sont envoyés sur Jibiat, seul le bandeau de l'équipe reste.</p>
      <button type="button" class="btn-primary" :disabled="busy" @click="savePreview">Enregistrer le bandeau</button>
      <p class="text-xs text-slate-600">Le changement apparaît dans l'ancienne application en 5 minutes au plus.</p>
    </div>

    <p class="text-sm">
      Déjà passés sur Jibiat par la bascule : <strong>{{ state.switched.merchants }}</strong> e-commerçant{{ state.switched.merchants > 1 ? 's' : '' }}
      et <strong>{{ state.switched.couriers }}</strong> livreur{{ state.switched.couriers > 1 ? 's' : '' }}.
    </p>
    </template>
  </section>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import http, { apiErrorMessage } from '../bootstrap/axios'
import { useToastStore } from '../stores/toasts'
import { dateTime } from '../utils/format'
import { confirmDialog } from '../composables/useDialog'

/**
 * Réglages › Reprise de l'ancienne application › Bascule : date à partir de laquelle les comptes de l'ancienne
 * application arrivent sur Jibiat déjà connectés, clé à mettre dans l'ancienne application, module à y copier.
 */
defineProps({ part: { type: String, default: 'schedule' } })
const toasts = useToastStore()
const state = ref(null)
const key = ref('')
const when = ref('')
const busy = ref(false)
const preview = ref({ team: false, merchants: false, couriers: false, message: '' })
const envLines = computed(() => `JIBIAT_URL=${window.location.origin}\nJIBIAT_SWITCH_KEY=${key.value}`)

// Valeur du champ date et heure (heure locale du navigateur)
function local(iso) {
  if (!iso) return ''
  const d = new Date(iso)
  const pad = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}

function apply(data) {
  state.value = data
  when.value = local(data.switch_at)
  if (data.preview) preview.value = { ...data.preview, message: data.preview.message || '' }
}

function savePreview() {
  return run(() => http.put('/company/legacy-switch/preview', { ...preview.value, message: preview.value.message || null }), 'Bandeau enregistré.')
}

async function run(request, success) {
  busy.value = true
  try {
    const { data } = await request()
    apply(data.data)
    if (success) toasts.success(success)
    return data.data
  } catch (e) {
    toasts.error(apiErrorMessage(e))
    return null
  } finally {
    busy.value = false
  }
}

function save(switchAt) {
  return run(() => http.put('/company/legacy-switch', { switch_at: switchAt }), switchAt ? 'Bascule programmée.' : 'Bascule annulée.')
}

async function now() {
  if (!await confirmDialog('Basculer maintenant ? Les e-commerçants et livreurs qui ouvrent l\'ancienne application arriveront sur Jibiat dès à présent.')) return
  save(new Date().toISOString())
}

async function createKey() {
  if (state.value.key_active && !await confirmDialog('Créer une nouvelle clé ? L\'ancienne ne fonctionnera plus : il faudra mettre la nouvelle dans l\'ancienne application.')) return
  const data = await run(() => http.post('/company/legacy-switch/key'))
  if (data) key.value = data.key
}

async function revoke() {
  if (!await confirmDialog('Désactiver la clé ? Plus personne ne sera envoyé sur Jibiat depuis l\'ancienne application.')) return
  await run(() => http.delete('/company/legacy-switch/key'), 'Clé désactivée.')
  key.value = ''
}

async function downloadModule() {
  const { data } = await http.get('/company/legacy-switch/module', { responseType: 'blob' })
  const url = URL.createObjectURL(data)
  const link = Object.assign(document.createElement('a'), { href: url, download: 'JibiatSwitch.php' })
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
    apply((await http.get('/company/legacy-switch')).data.data)
  } catch {
    state.value = null
  }
})
</script>
