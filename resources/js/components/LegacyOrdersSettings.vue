<template>
  <section v-if="state" class="card p-4 space-y-4">
    <div>
      <h2 class="font-semibold">Commandes de l'ancienne application</h2>
      <p class="text-sm text-gray-600">
        Jibiat va chercher les commandes dans l'ancienne application (lecture seule, rien n'y est modifié) : les
        {{ state.months }} derniers mois, toutes celles en cours et celles dont l'argent n'est pas encore soldé. Ensuite,
        chaque minute, les commandes créées ou modifiées là-bas (par les e-commerçants ou l'administration) arrivent ici.
        Aucun message n'est envoyé aux clients ni aux e-commerçants pendant la reprise.
      </p>
    </div>

    <!-- 1. Branchement -->
    <div class="space-y-2 rounded-lg border p-3">
      <p class="text-sm font-medium">1. Brancher l'ancienne application</p>
      <ol class="list-decimal space-y-1 pl-5 text-sm text-gray-700">
        <li>Copiez le module dans l'ancienne application (explications en tête du fichier) :
          <button type="button" class="text-blue-600" @click="downloadModule">télécharger JibiatSyncController.php</button>.</li>
        <li>Créez la clé de lecture et mettez la ligne affichée dans le fichier .env de l'ancienne application.</li>
        <li>Indiquez l'adresse de l'ancienne application, puis « Vérifier ».</li>
      </ol>
      <div v-if="key" class="space-y-2 rounded-lg bg-amber-50 p-3 text-sm">
        <p class="font-medium">Copiez cette ligne maintenant : la clé ne sera plus affichée.</p>
        <pre class="whitespace-pre-wrap break-all rounded bg-white p-2 text-xs">JIBIAT_SYNC_KEY={{ key }}</pre>
        <button type="button" class="text-xs text-blue-600" @click="copy(`JIBIAT_SYNC_KEY=${key}`)">Copier</button>
      </div>
      <div class="flex flex-wrap items-end gap-2">
        <div class="flex-1 min-w-[16rem]">
          <label class="label" for="legacy-url">Adresse de l'ancienne application</label>
          <input id="legacy-url" v-model="url" type="url" class="input" placeholder="https://ancienne-adresse.com">
        </div>
        <button type="button" class="btn-secondary" :disabled="busy || !url" @click="saveUrl">Enregistrer</button>
        <button type="button" class="btn-secondary" :disabled="busy" @click="createKey">{{ state.has_key ? 'Nouvelle clé' : 'Créer la clé' }}</button>
        <button type="button" class="btn-primary" :disabled="busy || !state.url || !state.has_key" @click="test">Vérifier</button>
      </div>
      <p v-if="checked" class="text-sm text-emerald-700">✅ L'ancienne application répond : {{ checked.commands }} commande(s) à reprendre<template v-if="checked.products">, {{ checked.products }} produit(s)</template>.</p>
    </div>

    <!-- 2. Simulation et reprise -->
    <div class="space-y-3 rounded-lg border p-3">
      <div class="flex flex-wrap items-center gap-2">
        <p class="text-sm font-medium flex-1">2. Reprendre les commandes</p>
        <span v-if="state.imported_at" class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-800">✓ Reprise faite le {{ dateTime(state.imported_at) }} · {{ state.orders }} course(s)</span>
      </div>

      <div v-if="running" class="space-y-1">
        <p class="text-sm">{{ job.mode === 'simulate' ? '🔍 Simulation' : '📥 Reprise' }} en cours : {{ job.done }} / {{ job.total || '…' }}</p>
        <div class="h-2 rounded bg-slate-200"><div class="h-2 rounded bg-blue-600" :style="{ width: `${job.total ? Math.round(job.done * 100 / job.total) : 3}%` }" /></div>
        <p class="text-xs text-gray-500">Vous pouvez quitter la page : la reprise continue sur le serveur.</p>
        <p v-if="job.status === 'stalled'" class="text-xs text-amber-700">⏸️ Interrompue (redémarrage du serveur) : elle repart toute seule d'ici une minute, là où elle s'était arrêtée.</p>
        <p v-else-if="job.error" class="text-xs text-amber-700">⚠️ L'ancienne application n'a pas répondu ({{ job.error }}) : nouvel essai dans quelques minutes.</p>
      </div>
      <p v-else-if="job?.status === 'failed'" class="field-error">{{ job.error }}</p>

      <template v-if="report">
        <p class="text-sm font-medium">{{ job.mode === 'simulate' ? 'Simulation (rien n\'a été enregistré)' : 'Résultat de la reprise' }}</p>
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
          <StatCard title="Commandes lues" :value="total" icon="📦" />
          <StatCard :title="job.mode === 'simulate' ? 'Seront créées' : 'Créées'" :value="report.actions.created" icon="✨" />
          <StatCard title="Déjà là / mises à jour" :value="report.actions.unchanged + report.actions.updated" icon="🔁" />
          <StatCard title="À vérifier" :value="report.actions.skipped + report.warnings.length" icon="⚠️" />
        </div>
        <table class="w-full text-sm">
          <thead><tr class="text-left text-gray-500"><th class="py-1">Ancien état</th><th class="text-right">Nombre</th><th class="pl-4">Dans Jibiat</th></tr></thead>
          <tbody>
            <tr v-for="(row, etat) in report.by_etat" :key="etat" class="border-t">
              <td class="py-1.5">{{ etat }}</td>
              <td class="text-right tabular-nums">{{ row.count }}</td>
              <td class="pl-4">
                <span v-for="(n, s) in row.statuses" :key="s" class="mr-1 inline-block rounded-full bg-slate-100 px-2 py-0.5 text-xs">{{ STATUS_LABELS[s] || s }}<template v-if="Object.keys(row.statuses).length > 1"> · {{ n }}</template></span>
              </td>
            </tr>
          </tbody>
        </table>
        <details v-if="report.skipped.length || report.warnings.length" class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">
          <summary class="cursor-pointer font-medium">⚠️ {{ report.skipped.length }} non reprise(s), {{ report.warnings.length }} à vérifier</summary>
          <ul class="mt-2 max-h-72 space-y-0.5 overflow-auto">
            <li v-for="(w, i) in report.skipped" :key="`s${i}`"><b>n° {{ w.id }}</b> {{ w.name }} — non reprise : {{ w.reason }}</li>
            <li v-for="(w, i) in report.warnings" :key="`w${i}`"><b>n° {{ w.id }}</b> {{ w.name }} — {{ w.reason }}</li>
          </ul>
        </details>
        <p v-if="report.products" class="text-sm text-gray-600">🛍️ {{ report.products.products }} produit(s) repris avec leur stock actuel<template v-if="report.products.skipped">, {{ report.products.skipped }} ignoré(s) (e-commerçant non repris)</template>.</p>
      </template>

      <p class="rounded-lg bg-blue-50 p-3 text-sm text-blue-900">
        Prix gardés tels quels : articles = montant − remise, livraison à la charge du client. Articles, notes, reports et frais
        arrivent dans l'historique de chaque course ; l'ancien numéro reste visible et se cherche. Une commune inconnue met
        la course dans la zone « À préciser ».
      </p>
      <div class="flex flex-wrap gap-2">
        <button type="button" class="btn-secondary" :disabled="busy || running || !ready" @click="start('simulate')">🔍 Simuler</button>
        <button type="button" class="btn-primary" :disabled="busy || running || !ready" @click="start('import')">{{ state.imported_at ? 'Reprendre à nouveau' : 'Reprendre les commandes' }}</button>
      </div>
    </div>

    <!-- 3. Soldes -->
    <div v-if="state.imported_at" class="space-y-3 rounded-lg border p-3">
      <div class="flex flex-wrap items-center gap-2">
        <p class="text-sm font-medium flex-1">3. Contrôle des soldes</p>
        <span v-if="state.balances_validated_at" class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-800">✓ Validés le {{ dateTime(state.balances_validated_at) }}</span>
        <button type="button" class="btn-secondary" :disabled="busy" @click="loadBalances">{{ balances ? 'Recalculer' : 'Comparer les soldes' }}</button>
      </div>
      <template v-if="balances">
        <div class="grid gap-3 lg:grid-cols-2">
          <BalanceTable title="E-commerçants : à leur reverser" old-label="Paiements « encours »" :rows="balances.merchants" :old="balances.totals.merchants_old" :jibiat="balances.totals.merchants_jibiat" />
          <BalanceTable title="Livreurs : argent encore en main" old-label="Livrées non remises" :rows="balances.couriers" :old="balances.totals.couriers_old" :jibiat="balances.totals.couriers_jibiat" />
        </div>
        <p :class="['text-sm', balances.totals.merchants_gaps + balances.totals.couriers_gaps ? 'text-amber-700' : 'text-emerald-700']">
          {{ balances.totals.merchants_gaps + balances.totals.couriers_gaps ? `⚠️ ${balances.totals.merchants_gaps + balances.totals.couriers_gaps} écart(s) : voyez les lignes en orange (e-commerçant ou livreur non repris, commande à vérifier).` : '✅ Les soldes de Jibiat sont identiques à ceux de l\'ancienne application.' }}
        </p>
        <button type="button" class="btn-success" :disabled="busy" @click="validateBalances">Valider ces soldes</button>
      </template>
    </div>

    <!-- 4. Copie en continu -->
    <div v-if="state.imported_at" class="space-y-3 rounded-lg border p-3">
      <div class="flex flex-wrap items-center gap-2">
        <p class="text-sm font-medium flex-1">4. Copie en continu</p>
        <span :class="['rounded-full px-2 py-0.5 text-xs font-semibold', state.enabled ? 'bg-blue-100 text-blue-800' : 'bg-slate-100 text-slate-600']">{{ state.enabled ? '● Active' : '⏸ En pause' }}</span>
      </div>
      <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
        <StatCard title="Dernière copie" :value="state.last_at ? since(state.last_at) : '—'" icon="🕒" />
        <StatCard title="Reçues aujourd'hui" :value="state.today?.created ?? 0" icon="📥" />
        <StatCard title="Mises à jour aujourd'hui" :value="state.today?.updated ?? 0" icon="🔁" />
        <StatCard title="Signalées après la bascule" :value="state.conflicts" icon="⚠️" />
      </div>
      <p v-if="state.last_error" class="field-error">⚠️ {{ state.last_error }}</p>
      <p class="text-sm text-gray-600">
        <template v-if="state.switch_at && new Date(state.switch_at) <= new Date()">Bascule faite : Jibiat fait foi. Une commande encore saisie dans l'ancienne application arrive comme nouvelle course (l'équipe est prévenue) ; une modification là-bas d'une course déjà ici est signalée, sans rien écraser.</template>
        <template v-else>Avant la bascule, l'ancienne application fait foi : ses changements remplacent ceux de la copie. Chaque nuit, les courses en cours sont relues.</template>
      </p>
      <div class="flex flex-wrap gap-2">
        <button type="button" class="btn-secondary" :disabled="busy" @click="toggle">{{ state.enabled ? '⏸ Mettre en pause' : '▶ Reprendre la copie' }}</button>
        <button type="button" class="btn-secondary" :disabled="busy" @click="syncNow">Copier maintenant</button>
      </div>
    </div>
  </section>
</template>

<script setup>
import { computed, defineComponent, h, onBeforeUnmount, onMounted, ref } from 'vue'
import http, { apiErrorMessage } from '../bootstrap/axios'
import StatCard from './StatCard.vue'
import { useToastStore } from '../stores/toasts'
import { dateTime, money, STATUS_LABELS } from '../utils/format'

/**
 * Réglages › Reprise de l'ancienne application › Commandes : branchement (module, clé, adresse), simulation et reprise
 * en arrière-plan, contrôle des soldes, copie en continu.
 */
const BalanceTable = defineComponent({
  props: { title: String, oldLabel: String, rows: Array, old: Number, jibiat: Number },
  setup(props) {
    return () => h('div', { class: 'rounded-lg border' }, [
      h('p', { class: 'border-b px-3 py-2 text-sm font-medium' }, props.title),
      h('div', { class: 'max-h-72 overflow-auto' }, h('table', { class: 'w-full text-sm' }, [
        h('thead', h('tr', { class: 'text-left text-xs text-gray-500' }, [h('th', { class: 'px-3 py-1' }, ''), h('th', { class: 'text-right font-normal' }, props.oldLabel), h('th', { class: 'px-3 text-right font-normal' }, 'Jibiat')])),
        h('tbody', props.rows.map((r) => h('tr', { key: r.legacy_id, class: ['border-t', r.gap ? 'bg-amber-50 text-amber-900' : ''] }, [
          h('td', { class: 'px-3 py-1' }, r.name),
          h('td', { class: 'text-right tabular-nums whitespace-nowrap' }, money(r.old)),
          h('td', { class: 'px-3 text-right tabular-nums whitespace-nowrap' }, money(r.jibiat)),
        ]))),
      ])),
      h('p', { class: 'flex justify-between border-t px-3 py-2 text-sm font-semibold' }, [h('span', 'Total'), h('span', `${money(props.old)} · ${money(props.jibiat)}`)]),
    ])
  },
})

const toasts = useToastStore()
const state = ref(null)
const url = ref('')
const key = ref('')
const checked = ref(null)
const balances = ref(null)
const busy = ref(false)

const job = computed(() => state.value?.job ?? null)
const running = computed(() => ['running', 'stalled'].includes(job.value?.status))
const report = computed(() => (job.value?.status === 'done' ? job.value.report : null))
const total = computed(() => Object.values(report.value?.by_etat ?? {}).reduce((n, r) => n + r.count, 0))
const ready = computed(() => state.value?.url && state.value?.has_key)

function since(iso) {
  const s = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 1000))
  return s < 90 ? `il y a ${s} s` : s < 5400 ? `il y a ${Math.round(s / 60)} min` : dateTime(iso)
}

async function call(request, success) {
  busy.value = true
  try {
    const { data } = await request()
    if (data.data?.url !== undefined) {
      state.value = data.data
      url.value = data.data.url || url.value
    }
    if (success) toasts.success(success)
    return data.data
  } catch (e) {
    toasts.error(apiErrorMessage(e))
    return null
  } finally {
    busy.value = false
  }
}

const load = () => call(() => http.get('/company/legacy-orders'))
const saveUrl = () => call(() => http.put('/company/legacy-orders', { url: url.value }), 'Adresse enregistrée.')

async function createKey() {
  if (state.value.has_key && !window.confirm('Créer une nouvelle clé ? L\'ancienne ne fonctionnera plus : il faudra mettre la nouvelle dans le .env de l\'ancienne application.')) return
  const data = await call(() => http.post('/company/legacy-orders/key'))
  if (data) key.value = data.key
}

async function test() {
  checked.value = await call(() => http.post('/company/legacy-orders/test'))
}

async function start(mode) {
  if (mode === 'import' && !window.confirm('Reprendre les commandes maintenant ? Aucun message ne sera envoyé ; relancer plus tard ne crée pas de doublon.')) return
  await call(() => http.post(`/company/legacy-orders/run/${mode}`))
}

async function loadBalances() {
  balances.value = await call(() => http.get('/company/legacy-orders/balances'))
}

const validateBalances = () => call(() => http.post('/company/legacy-orders/balances/validate'), 'Soldes validés.')
const toggle = () => call(() => http.put('/company/legacy-orders/enabled', { enabled: !state.value.enabled }), state.value.enabled ? 'Copie en pause.' : 'Copie reprise.')

async function syncNow() {
  const data = await call(() => http.post('/company/legacy-orders/sync'))
  if (data?.counts) toasts.success(`${data.counts.created} nouvelle(s), ${data.counts.updated} mise(s) à jour.`)
}

async function downloadModule() {
  const { data } = await http.get('/company/legacy-orders/module', { responseType: 'blob' })
  const href = URL.createObjectURL(data)
  Object.assign(document.createElement('a'), { href, download: 'JibiatSyncController.php' }).click()
  URL.revokeObjectURL(href)
}

async function copy(text) {
  try {
    await navigator.clipboard.writeText(text)
    toasts.success('Copié.')
  } catch {
    toasts.error('Copie impossible : sélectionnez le texte à la main.')
  }
}

// Avancement de la reprise et de la copie en continu
let timer = null
onMounted(async () => {
  await load()
  timer = setInterval(() => {
    if (!document.hidden && !busy.value && (running.value || state.value?.enabled)) {
      http.get('/company/legacy-orders').then(({ data }) => { state.value = data.data }).catch(() => {})
    }
  }, 4000)
})
onBeforeUnmount(() => clearInterval(timer))
</script>
