<template>
  <div class="space-y-4">
    <div>
      <h1 class="text-xl font-bold">Applications à leur nom</h1>
      <p class="text-sm text-gray-500">Les applications Android E-commerçant et Livreur au nom de l'entreprise, publiées sur son compte Google Play. Option de la formule la plus haute.</p>
    </div>

    <p v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</p>
    <p v-if="loaded && !githubReady" class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">
      Le jeton GitHub n'est pas réglé sur le serveur (GITHUB_APPS_TOKEN dans le fichier .env) : les versions ne peuvent pas être fabriquées.
    </p>

    <div v-if="loaded" class="grid gap-4 lg:grid-cols-[20rem_1fr]">
      <!-- Entreprises qui ont l'option -->
      <section class="card p-4 space-y-2 self-start">
        <h2 class="font-semibold">Entreprises ({{ apps.length }})</h2>
        <p v-if="!apps.length" class="text-sm text-gray-500">Aucune entreprise pour l'instant.</p>
        <button v-for="a in apps" :key="a.company.id" type="button"
          :class="['flex w-full items-center gap-3 rounded-lg border p-2.5 text-left', selectedId === a.company.id ? 'border-emerald-200 bg-emerald-50' : 'border-transparent hover:bg-slate-50']"
          @click="select(a.company.id)">
          <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-slate-800 text-xs font-bold text-white">{{ initials(a.company.name) }}</span>
          <span class="min-w-0 flex-1">
            <span class="block truncate font-semibold">{{ a.company.name }}</span>
            <span class="block truncate text-xs text-gray-500">{{ a.last_version ? `Version ${a.last_version}` : 'Aucune version' }}</span>
          </span>
          <span :class="['shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold', STATE[a.state] || STATE.todo]">{{ a.state_label }}</span>
        </button>
        <form v-if="owner && companies.length" class="flex gap-2 pt-2" @submit.prevent="add">
          <select v-model="adding" class="input min-w-0 flex-1" required>
            <option value="" disabled>Ajouter une entreprise…</option>
            <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.name }}</option>
          </select>
          <button class="btn-secondary" :disabled="busy || !adding">Ajouter</button>
        </form>
      </section>

      <!-- Fiche de l'entreprise choisie -->
      <div v-if="detail" class="space-y-4 min-w-0">
        <section class="card p-4 space-y-4">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
              <h2 class="text-lg font-semibold">{{ detail.company.name }}</h2>
              <p class="text-xs text-gray-500">Icônes et couleurs prises dans l'apparence de l'entreprise (Paramètres › Apparence des espaces)</p>
              <p v-if="!detail.top_plan" class="mt-1 text-xs text-amber-700">Cette entreprise n'est pas sur la formule la plus haute.</p>
            </div>
            <div class="flex flex-wrap gap-2">
              <RouterLink :to="`/console/entreprises/${detail.company.id}`" class="btn-secondary">Fiche de l'entreprise</RouterLink>
              <button v-if="owner" class="btn-primary" :disabled="busy || !detail.enabled || building" @click="build">
                {{ building ? 'Fabrication en cours…' : 'Fabriquer une nouvelle version' }}
              </button>
            </div>
          </div>
          <div class="flex flex-wrap items-center gap-6">
            <div v-for="(a, key) in detail.apps" :key="key" class="text-center">
              <img :src="a.icon_url" alt="" class="mx-auto h-16 w-16 rounded-2xl shadow" :style="{ background: a.color }">
              <p class="mt-1.5 text-xs font-semibold">{{ a.name }}</p>
            </div>
            <dl class="min-w-0 flex-1 divide-y text-sm">
              <div class="flex justify-between gap-3 py-2"><dt class="text-gray-500">Application e-commerçant</dt><dd class="truncate font-mono text-xs">{{ detail.apps.ecommercant.app_id }}</dd></div>
              <div class="flex justify-between gap-3 py-2"><dt class="text-gray-500">Application livreur</dt><dd class="truncate font-mono text-xs">{{ detail.apps.livreur.app_id }}</dd></div>
              <div class="flex justify-between gap-3 py-2"><dt class="text-gray-500">Adresse</dt><dd class="truncate font-mono text-xs">{{ detail.url }}</dd></div>
              <div class="flex justify-between gap-3 py-2"><dt class="text-gray-500">Clé de signature</dt><dd>🔒 gardée par Jibiat<span v-if="detail.key_created_at"> · créée le {{ date(detail.key_created_at) }}</span></dd></div>
            </dl>
          </div>
        </section>

        <div class="grid gap-4 xl:grid-cols-2">
          <section class="card p-4 space-y-2">
            <h2 class="font-semibold">Avant la publication</h2>
            <ul class="space-y-1.5 text-sm">
              <li v-for="c in detail.checklist" :key="c.key" class="flex gap-2">
                <span :class="c.ok ? 'font-bold text-emerald-600' : 'font-bold text-amber-600'">{{ c.ok ? '✓' : '!' }}</span>
                <span class="min-w-0">{{ c.label }}<span v-if="c.hint" class="block truncate text-xs text-gray-500">{{ c.hint }}</span></span>
              </li>
            </ul>
          </section>

          <section class="card p-4 space-y-3">
            <h2 class="font-semibold">Versions fabriquées</h2>
            <p v-if="!detail.builds.length" class="text-sm text-gray-500">Aucune version pour l'instant.</p>
            <ul v-else class="divide-y text-sm">
              <li v-for="b in detail.builds" :key="b.id" class="py-2 space-y-1">
                <div class="flex flex-wrap items-center gap-2">
                  <span class="font-semibold">{{ b.version_name }}</span>
                  <span class="text-xs text-gray-500">{{ date(b.created_at) }}<span v-if="b.by"> · {{ b.by }}</span></span>
                  <span :class="['rounded-full px-2 py-0.5 text-xs font-semibold', BUILD[b.status]]">{{ b.status_label }}</span>
                  <a v-if="b.run_url" :href="b.run_url" target="_blank" rel="noopener" class="text-xs text-blue-700 hover:underline">GitHub</a>
                  <button v-if="owner && b.status === 'ready'" class="ml-auto text-xs text-emerald-700" @click="markPublished(b)">Marquer publiée</button>
                </div>
                <p v-if="b.error" class="text-xs text-red-700">{{ b.error }}</p>
                <div v-if="b.files.length" class="flex flex-wrap gap-2">
                  <button v-for="f in b.files" :key="`${f.app}-${f.kind}`" class="rounded border px-2 py-0.5 text-xs hover:bg-slate-50" @click="download(b, f)">
                    {{ f.app === 'ecommercant' ? 'E-commerçant' : 'Livreur' }} · {{ f.kind === 'aab' ? 'Play Store (.aab)' : 'Essai (.apk)' }}
                  </button>
                </div>
              </li>
            </ul>
            <div class="flex flex-wrap gap-2 pt-1">
              <span class="cursor-not-allowed rounded-lg border border-dashed px-3 py-1.5 text-xs text-gray-400" title="Étape 4">Envoyer au Play Store (bientôt)</span>
              <span class="cursor-not-allowed rounded-lg border border-dashed px-3 py-1.5 text-xs text-gray-400" title="Étape 3">iPhone (bientôt)</span>
            </div>
          </section>
        </div>

        <!-- Réglages des applications et de la fiche Play Store -->
        <form class="card p-4 space-y-3" @submit.prevent="save">
          <h2 class="font-semibold">Réglages</h2>
          <label class="flex items-center gap-2 text-sm font-medium">
            <input v-model="form.google_linked" type="checkbox" class="h-4 w-4" :disabled="!owner">
            Compte Google Play de l'entreprise relié (Jibiat invité comme gestionnaire)
          </label>
          <div class="grid gap-3 sm:grid-cols-2">
            <label class="block text-sm">Nom de l'application e-commerçant
              <input v-model="form.merchant_app_name" maxlength="30" class="input mt-1 w-full" :placeholder="detail.defaults.merchant_app_name" :disabled="!owner">
            </label>
            <label class="block text-sm">Nom de l'application livreur
              <input v-model="form.courier_app_name" maxlength="30" class="input mt-1 w-full" :placeholder="detail.defaults.courier_app_name" :disabled="!owner">
            </label>
            <label class="block text-sm">Identifiant e-commerçant
              <input v-model="form.merchant_app_id" class="input mt-1 w-full font-mono" :disabled="!owner || locked">
            </label>
            <label class="block text-sm">Identifiant livreur
              <input v-model="form.courier_app_id" class="input mt-1 w-full font-mono" :disabled="!owner || locked">
            </label>
          </div>
          <p v-if="locked" class="text-xs text-gray-500">Les identifiants ne changent plus après la première version : Google Play les garde pour toujours.</p>
          <label class="block text-sm">Description courte (Play Store, 80 caractères)
            <input v-model="form.short_description" maxlength="80" class="input mt-1 w-full" :disabled="!owner">
          </label>
          <label class="block text-sm">Description longue (Play Store)
            <textarea v-model="form.full_description" maxlength="4000" rows="5" class="input mt-1 w-full" :disabled="!owner" />
          </label>
          <div class="flex flex-wrap items-center justify-between gap-2">
            <label class="flex items-center gap-2 text-sm">
              <input v-model="form.enabled" type="checkbox" class="h-4 w-4" :disabled="!owner"> Option activée
            </label>
            <button v-if="owner" class="btn-primary" :disabled="busy">Enregistrer</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { consoleCan } from '../../roles'
import { useAuthStore } from '../../stores/auth'
import { useToastStore } from '../../stores/toasts'
import { date } from '../../utils/format'

const STATE = {
  ready: 'bg-emerald-100 text-emerald-800',
  published: 'bg-slate-100 text-slate-700',
  building: 'bg-blue-100 text-blue-800',
  link: 'bg-amber-100 text-amber-800',
  failed: 'bg-red-100 text-red-700',
  todo: 'bg-slate-100 text-slate-700',
  off: 'bg-slate-100 text-slate-500',
}
const BUILD = {
  queued: 'bg-blue-100 text-blue-800',
  building: 'bg-blue-100 text-blue-800',
  ready: 'bg-emerald-100 text-emerald-800',
  failed: 'bg-red-100 text-red-700',
  published: 'bg-slate-100 text-slate-700',
}

const auth = useAuthStore()
const toasts = useToastStore()
const route = useRoute()
const router = useRouter()
const owner = computed(() => consoleCan(auth.role, 'owner'))
const loaded = ref(false)
const busy = ref(false)
const error = ref('')
const apps = ref([])
const companies = ref([])
const githubReady = ref(true)
const adding = ref('')
const selectedId = ref(null)
const detail = ref(null)
const form = reactive({})
let timer = null

const building = computed(() => detail.value?.builds.some((b) => ['queued', 'building'].includes(b.status)))
const locked = computed(() => detail.value?.builds.some((b) => b.status !== 'failed'))

function initials(name) {
  return (name || '?').split(/\s+/).map((p) => p[0]).slice(0, 2).join('').toUpperCase()
}

function show(data) {
  detail.value = data
  Object.assign(form, data.settings, { enabled: data.enabled })
  const row = apps.value.find((a) => a.company.id === data.company.id)
  if (row) Object.assign(row, { state: data.state, state_label: data.state_label, last_version: data.last_version, enabled: data.enabled })
}

async function loadList() {
  const { data } = await http.get('/console/branded-apps')
  apps.value = data.data.apps
  companies.value = data.data.companies
  githubReady.value = data.data.github_ready
  loaded.value = true
}

async function select(id) {
  selectedId.value = id
  router.replace({ query: { entreprise: id } })
  try {
    show((await http.get(`/console/branded-apps/${id}`)).data.data)
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

async function call(request, done) {
  busy.value = true
  try {
    const { data } = await request()
    show(data.data)
    if (done) toasts.success(done)
    error.value = ''
    return data
  } catch (e) {
    error.value = apiErrorMessage(e)
    return null
  } finally {
    busy.value = false
  }
}

async function add() {
  const id = adding.value
  const data = await call(() => http.post(`/console/branded-apps/${id}`), 'Entreprise ajoutée. Sa clé de signature est créée.')
  if (data) {
    adding.value = ''
    await loadList()
    selectedId.value = id
    router.replace({ query: { entreprise: id } })
  }
}

const save = () => call(() => http.put(`/console/branded-apps/${selectedId.value}`, { ...form }), 'Réglages enregistrés.')

async function build() {
  const data = await call(() => http.post(`/console/branded-apps/${selectedId.value}/builds`))
  if (!data) return
  if (data.build.status === 'failed') toasts.error(data.build.error)
  else toasts.success(`Version ${data.build.version_name} lancée sur GitHub : une vingtaine de minutes.`)
}

const markPublished = (b) => call(() => http.post(`/console/branded-apps/${selectedId.value}/builds/${b.id}/published`), 'Version marquée publiée.')

async function download(b, f) {
  try {
    const response = await http.get(`/console/branded-apps/${selectedId.value}/builds/${b.id}/${f.app}.${f.kind}`, { responseType: 'blob' })
    const url = URL.createObjectURL(response.data)
    const link = document.createElement('a')
    link.href = url
    link.download = `${detail.value.company.slug}-${f.app}-${b.version_name}.${f.kind}`
    link.click()
    URL.revokeObjectURL(url)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

onMounted(async () => {
  try {
    await loadList()
    const wanted = Number(route.query.entreprise) || apps.value[0]?.company.id
    if (wanted) await select(wanted)
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
  // Fabrication en cours : l'état est relu toutes les 20 secondes
  timer = setInterval(() => {
    if (building.value && selectedId.value) http.get(`/console/branded-apps/${selectedId.value}`).then(({ data }) => show(data.data)).catch(() => {})
  }, 20000)
})
onBeforeUnmount(() => clearInterval(timer))
</script>
