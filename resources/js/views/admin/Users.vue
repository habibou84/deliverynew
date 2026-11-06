<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h1 class="text-xl font-bold">Utilisateurs</h1>
      <button v-if="auth.can('users.manage')" class="btn-primary" @click="openForm()">+ Nouveau</button>
    </div>

    <!-- Compteurs -->
    <div v-if="summary" class="grid grid-cols-2 gap-3 lg:grid-cols-4">
      <button type="button" class="card p-3 text-left" @click="setTab('team')">
        <p class="text-xs text-gray-500">Équipe</p>
        <p class="text-2xl font-bold">{{ summary.team }}</p>
        <p class="text-xs text-gray-500">{{ teamBreakdown || '—' }}</p>
      </button>
      <button type="button" class="card p-3 text-left" @click="setTab('couriers')">
        <p class="text-xs text-gray-500">Livreurs</p>
        <p class="text-2xl font-bold">{{ summary.couriers }}</p>
        <p class="text-xs text-gray-500">{{ roleCount('courier', 'active') }} actif{{ roleCount('courier', 'active') > 1 ? 's' : '' }} · {{ roleCount('courier', 'suspended') }} suspendu{{ roleCount('courier', 'suspended') > 1 ? 's' : '' }}</p>
      </button>
      <button type="button" class="card p-3 text-left" @click="setTab('merchants')">
        <p class="text-xs text-gray-500">Comptes e-commerçants</p>
        <p class="text-2xl font-bold">{{ summary.merchants }}</p>
        <p class="text-xs text-gray-500">{{ roleCount('merchant_owner') }} propriétaire{{ roleCount('merchant_owner') > 1 ? 's' : '' }} · {{ roleCount('merchant_staff') }} employé{{ roleCount('merchant_staff') > 1 ? 's' : '' }}</p>
      </button>
      <button type="button" :class="['card p-3 text-left', summary.inactive ? 'border-amber-300 bg-amber-50' : '']" @click="setTab('inactive')">
        <p :class="['text-xs', summary.inactive ? 'text-amber-800' : 'text-gray-500']">{{ summary.inactive ? '⚠️ ' : '' }}À vérifier</p>
        <p :class="['text-2xl font-bold', summary.inactive ? 'text-amber-800' : '']">{{ summary.inactive }}</p>
        <p :class="['text-xs', summary.inactive ? 'text-amber-800' : 'text-gray-500']">équipe et livreurs jamais connectés ou inactifs depuis {{ summary.inactive_days }} j</p>
      </button>
    </div>

    <!-- Onglets et recherche -->
    <div class="card space-y-3 p-3">
      <div class="flex flex-wrap gap-2 text-sm">
        <button
          v-for="t in TABS" :key="t.key" type="button"
          :class="['rounded-full px-3 py-1', tab === t.key ? 'bg-slate-900 text-white' : t.key === 'inactive' && summary?.inactive ? 'border border-amber-300 bg-amber-50 text-amber-800' : 'border border-slate-300 bg-white']"
          @click="setTab(t.key)"
        >
          {{ t.label }}<span v-if="summary" class="ml-1 opacity-75">{{ summary[t.key] }}</span>
        </button>
      </div>
      <div class="flex flex-wrap gap-2">
        <input v-model="search" type="search" class="input min-w-48 flex-1" placeholder="🔍 Nom, téléphone ou e-mail…" @input="debouncedLoad">
        <select v-if="tab === 'team'" v-model="role" class="input !w-auto" @change="reload">
          <option value="">Tous les rôles</option>
          <option v-for="r in teamRoles" :key="r.value" :value="r.value">{{ r.label }}</option>
        </select>
      </div>
    </div>

    <!-- Comptes dont la suppression a été demandée depuis le profil : annulable avant l'effacement -->
    <section v-if="deletions.length" class="card space-y-2 border-red-200 p-4">
      <h2 class="font-semibold text-red-800">Demandes de suppression de compte ({{ deletions.length }})</h2>
      <p class="text-xs text-gray-600">Comptes fermés à la demande de leur titulaire. Les données personnelles sont effacées à la date indiquée ; les courses et la comptabilité restent, sous un nom anonyme.</p>
      <ul class="divide-y text-sm">
        <li v-for="d in deletions" :key="d.id" class="flex flex-wrap items-center gap-3 py-2">
          <span class="min-w-0 flex-1 font-medium">{{ d.name }}</span>
          <span class="text-gray-500">Effacement le {{ date(d.erase_after) }}</span>
          <button v-if="auth.can('users.manage')" class="text-emerald-700" :disabled="cancelling === d.id" @click="cancelDeletion(d)">Annuler et rouvrir le compte</button>
        </li>
      </ul>
    </section>

    <div v-if="loadError" class="card flex flex-wrap items-center gap-3 border-red-200 bg-red-50 p-4 text-sm text-red-800">
      <span class="flex-1">La liste n'a pas pu être chargée : {{ loadError }}</span>
      <button type="button" class="btn-secondary" @click="load">Réessayer</button>
    </div>

    <div v-else class="card">
      <table class="w-full text-sm">
        <thead class="bg-slate-50 text-left text-xs text-gray-500">
          <tr>
            <th class="p-3">Nom</th>
            <th class="hidden p-3 md:table-cell">Téléphone</th>
            <th class="hidden p-3 sm:table-cell">Rôle</th>
            <th class="hidden p-3 md:table-cell">Statut</th>
            <th class="hidden p-3 lg:table-cell">Dernière connexion</th>
            <th class="p-3" />
          </tr>
        </thead>
        <tbody class="divide-y">
          <tr v-for="u in users" :key="u.id" :class="u.status === 'suspended' ? 'bg-slate-50 text-gray-500' : ''">
            <td class="p-3">
              <p class="font-medium">
                <RouterLink v-if="ficheLink(u)" :to="ficheLink(u)" class="hover:underline">{{ u.name }}</RouterLink>
                <template v-else>{{ u.name }}</template>
                <span v-if="u.id === auth.user.id" class="text-xs text-gray-400"> (vous)</span>
              </p>
              <p v-if="u.merchant" class="text-xs text-gray-500">{{ u.merchant.business_name }}</p>
              <p class="text-xs text-gray-500 sm:hidden">{{ u.role_label }}<template v-if="isProtected(u)"> · 🔒 protégé</template></p>
              <p class="text-xs text-gray-500 md:hidden">{{ u.phone }}</p>
              <p class="flex flex-wrap gap-1 md:hidden"><span v-for="b in badges(u)" :key="b.text" :class="['rounded px-1.5 py-0.5 text-xs', b.cls]">{{ b.text }}</span></p>
            </td>
            <td class="hidden p-3 md:table-cell">{{ u.phone }}<p v-if="u.email" class="text-xs text-gray-500">{{ u.email }}</p></td>
            <td class="hidden p-3 sm:table-cell">
              {{ u.role_label }}
              <span v-if="isProtected(u)" class="ml-1 whitespace-nowrap rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600" title="Ses accès ne peuvent être changés que par elle-même ou par le propriétaire de la plateforme">🔒 protégé</span>
            </td>
            <td class="hidden p-3 md:table-cell">
              <span class="flex flex-wrap gap-1"><span v-for="b in badges(u)" :key="b.text" :class="['rounded px-1.5 py-0.5 text-xs', b.cls]">{{ b.text }}</span></span>
            </td>
            <td class="hidden p-3 text-xs text-gray-500 lg:table-cell">{{ u.last_login_at ? ago(u.last_login_at) : `créé le ${date(u.created_at)}` }}</td>
            <td class="whitespace-nowrap p-3 text-right">
              <button v-if="u.status === 'suspended' && u.abilities?.access" type="button" class="mr-2 rounded-lg border border-emerald-500 px-2 py-1 text-emerald-700" @click="ask('reactivate', u)">Réactiver</button>
              <span v-if="actions(u).length" class="relative inline-block">
                <button type="button" :class="['rounded-lg border px-2 py-1', menu === u.id ? 'border-emerald-500 bg-emerald-50' : '']" :aria-label="`Actions pour ${u.name}`" @click.stop="menu = menu === u.id ? null : u.id">⋯</button>
                <div v-if="menu === u.id" class="absolute right-0 top-9 z-20 w-72 rounded-xl border bg-white p-1 text-left shadow-lg" @click.stop>
                  <template v-for="a in actions(u)" :key="a.key">
                    <hr v-if="a.danger" class="my-1">
                    <button type="button" :class="['block w-full rounded-lg px-3 py-2 text-left hover:bg-slate-50', a.danger ? 'text-red-600' : '']" @click="run(a.key, u)">{{ a.label }}</button>
                  </template>
                </div>
              </span>
            </td>
          </tr>
          <tr v-if="!users.length && !loading"><td colspan="6" class="p-6 text-center text-gray-500">{{ search ? 'Personne ne correspond à cette recherche.' : 'Personne dans cet onglet.' }}</td></tr>
        </tbody>
      </table>
      <div v-if="meta && meta.total > meta.per_page" class="flex items-center justify-between border-t p-3 text-sm text-gray-500">
        <span>{{ meta.from }} à {{ meta.to }} sur {{ meta.total }}</span>
        <span class="flex gap-1">
          <button type="button" class="rounded border px-2 py-0.5 disabled:opacity-40" :disabled="meta.current_page <= 1" aria-label="Page précédente" @click="go(-1)">‹</button>
          <button type="button" class="rounded border px-2 py-0.5 disabled:opacity-40" :disabled="meta.current_page >= meta.last_page" aria-label="Page suivante" @click="go(1)">›</button>
        </span>
      </div>
      <p v-else-if="meta && meta.total" class="border-t p-3 text-sm text-gray-500">{{ meta.total }} compte{{ meta.total > 1 ? 's' : '' }}</p>
    </div>

    <!-- Créer / modifier -->
    <Modal :open="form.open" :title="form.id ? `Modifier : ${form.original.name}` : 'Nouvel utilisateur'" @close="form.open = false">
      <form class="space-y-3" @submit.prevent="save">
        <div v-if="form.error" class="rounded border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{{ form.error }}</div>
        <p v-if="form.id && !form.credentials" class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs text-slate-600">
          🔒 {{ form.original.name }} est administrateur. Vous pouvez corriger son nom. Son téléphone, son mot de passe, son rôle et son statut ne peuvent être changés que par lui-même ou par le propriétaire de la plateforme.
        </p>
        <div><label class="label">Nom *</label><input v-model="form.data.name" class="input" required></div>
        <div class="grid grid-cols-2 gap-3">
          <div><label class="label">Téléphone *</label><input v-model="form.data.phone" class="input disabled:bg-slate-50 disabled:text-gray-500" required inputmode="tel" :disabled="!form.credentials"></div>
          <div><label class="label">E-mail</label><input v-model="form.data.email" type="email" class="input disabled:bg-slate-50 disabled:text-gray-500" :disabled="!form.credentials"></div>
        </div>
        <div v-if="form.access">
          <p class="label">Rôle *</p>
          <div class="space-y-1.5">
            <label v-for="r in assignable" :key="r.value" :class="['flex cursor-pointer gap-2 rounded-lg border px-3 py-2', form.data.role === r.value ? 'border-2 border-emerald-500 bg-emerald-50' : '']">
              <input v-model="form.data.role" type="radio" name="role" :value="r.value" class="mt-1">
              <span><b>{{ r.label }}</b><br><span class="text-xs text-gray-500">{{ ROLE_HELP[r.value] }}</span></span>
            </label>
          </div>
        </div>
        <div v-else-if="form.id"><p class="label">Rôle</p><p class="input bg-slate-50 text-gray-500">{{ form.original.role_label }}</p></div>
        <div v-if="form.credentials">
          <label class="label">{{ form.id ? 'Nouveau mot de passe (laisser vide pour ne pas changer)' : 'Mot de passe (facultatif, 8 caractères min.)' }}</label>
          <div class="flex">
            <input v-model="form.data.password" :type="showPassword ? 'text' : 'password'" class="input rounded-r-none" autocomplete="new-password" :placeholder="form.id ? '' : 'Vide : un lien d\'invitation à envoyer par WhatsApp'">
            <button type="button" class="rounded-r-lg border border-l-0 border-slate-300 px-3" :aria-label="showPassword ? 'Masquer le mot de passe' : 'Afficher le mot de passe'" @click="showPassword = !showPassword">{{ showPassword ? '🙈' : '👁️' }}</button>
          </div>
          <p v-if="!form.id" class="mt-1 text-xs text-gray-500">Sans mot de passe, vous recevez un lien personnel à envoyer par WhatsApp : la personne arrive connectée et choisit son mot de passe si elle veut.</p>
        </div>
        <button class="btn-primary w-full" :disabled="form.saving">{{ !form.id ? (form.data.password ? 'Créer' : 'Créer et préparer l\'invitation') : form.credentials ? 'Enregistrer' : 'Enregistrer le nom' }}</button>
        <p v-if="form.id" class="text-center text-xs text-gray-500">Le statut se change depuis le menu ⋯ (Suspendre ou Réactiver).</p>
      </form>
    </Modal>

    <!-- Confirmation : suspendre, réactiver, déconnecter -->
    <Modal :open="!!confirm" :title="confirm?.title || ''" @close="confirm = null">
      <div v-if="confirm" class="space-y-3 text-sm">
        <p class="text-gray-600">{{ confirm.text }}</p>
        <div class="flex gap-2">
          <button type="button" :class="['flex-1', confirm.danger ? 'btn-danger' : 'btn-primary']" :disabled="busy" @click="confirm.run()">{{ confirm.button }}</button>
          <button type="button" class="btn-secondary flex-1" @click="confirm = null">Annuler</button>
        </div>
      </div>
    </Modal>

    <!-- Historique du compte -->
    <Modal :open="!!history" :title="history ? `Historique : ${history.user.name}` : ''" @close="history = null">
      <div v-if="history" class="text-sm">
        <p v-if="history.loading" class="text-gray-500">Chargement…</p>
        <ul v-else-if="history.events.length" class="space-y-2">
          <li v-for="e in history.events" :key="e.id" class="flex gap-3">
            <span class="w-28 shrink-0 text-xs text-gray-400">{{ dateTime(e.at) }}</span>
            <span>{{ e.text }}<template v-if="e.by">, par <b>{{ e.by }}</b></template></span>
          </li>
        </ul>
        <p v-else class="text-gray-500">Rien d'enregistré pour ce compte (l'historique commence avec cette version de la page).</p>
      </div>
    </Modal>

    <!-- Lien d'invitation : l'administrateur l'envoie depuis son propre WhatsApp (aucun SMS) -->
    <Modal :open="!!invitation" title="Invitation à envoyer" @close="invitation = null">
      <div v-if="invitation" class="space-y-3 text-sm">
        <p>Envoyez ce lien à <strong>{{ invitation.user.name }}</strong> ({{ invitation.user.phone }}). Il est personnel, ne sert qu'une fois et expire le {{ dateTime(invitation.expires_at) }}. En l'ouvrant, la personne arrive connectée dans son espace.</p>
        <a :href="invitation.whatsapp_link" target="_blank" rel="noopener" class="flex w-full items-center justify-center gap-2 rounded-xl bg-[#25D366] py-3 font-semibold text-white hover:bg-[#1ebe5b]">
          💬 Envoyer par WhatsApp
        </a>
        <div class="flex gap-2">
          <input :value="invitation.url" readonly class="input flex-1 text-xs" @focus="$event.target.select()">
          <button type="button" class="btn-secondary" @click="copy(invitation.url)">Copier</button>
        </div>
        <p class="text-xs text-gray-500">Pas de WhatsApp ? Donnez-lui le lien autrement, ou renseignez un mot de passe dans « Modifier ».</p>
      </div>
    </Modal>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import Modal from '../../components/Modal.vue'
import { useAuthStore } from '../../stores/auth'
import { useToastStore } from '../../stores/toasts'
import { date, dateTime } from '../../utils/format'
import { confirmDialog } from '../../composables/useDialog'

/** Équipe › Utilisateurs : onglets, compteurs, recherche, actions par compte, historique */
const TABS = [
  { key: 'team', label: 'Équipe' },
  { key: 'couriers', label: 'Livreurs' },
  { key: 'merchants', label: 'E-commerçants' },
  { key: 'suspended', label: 'Suspendus' },
  { key: 'inactive', label: 'À vérifier' },
]
const ROLE_HELP = {
  admin: 'Tout, y compris les réglages, l\'équipe et l\'argent.',
  dispatcher: 'Crée et répartit les courses, voit l\'équipe. Ne voit pas la caisse.',
  cashier: 'Reçoit l\'argent des livreurs, paie les e-commerçants. Ne répartit pas les courses.',
  hub_agent: 'Reçoit et rend les colis au dépôt, gère le stock.',
  courier: 'Application livreur : ses missions et sa caisse.',
}
const SHORT = { admin: 'admin', dispatcher: 'dispatch', cashier: 'caisse', hub_agent: 'dépôt' }
const PER_PAGE = 50

const auth = useAuthStore()
const toasts = useToastStore()
const route = useRoute()
const router = useRouter()
const tab = ref(TABS.some((t) => t.key === route.query.onglet) ? route.query.onglet : 'team')
const search = ref(typeof route.query.q === 'string' ? route.query.q : '')
const role = ref('')
const page = ref(1)
const users = ref([])
const meta = ref(null)
const summary = ref(null)
const loading = ref(false)
const loadError = ref('')
const assignable = ref([])
const deletions = ref([])
const cancelling = ref(null)
const menu = ref(null)
const busy = ref(false)
const confirm = ref(null)
const history = ref(null)
const invitation = ref(null)
const showPassword = ref(false)
const form = reactive({ open: false, id: null, original: {}, data: {}, credentials: true, access: true, error: '', saving: false })

const teamRoles = computed(() => assignable.value.filter((r) => r.value !== 'courier'))
const roleCount = (r, status = null) => {
  const counts = summary.value?.roles?.[r] || {}
  return status ? counts[status] || 0 : Object.values(counts).reduce((a, b) => a + b, 0)
}
const teamBreakdown = computed(() => Object.keys(SHORT).filter((r) => roleCount(r)).map((r) => `${roleCount(r)} ${SHORT[r]}`).join(' · '))

function ago(iso) {
  const min = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 60000))
  if (min < 2) return 'à l\'instant'
  if (min < 60) return `il y a ${min} min`
  if (min < 1440) return `il y a ${Math.round(min / 60)} h`
  return dateTime(iso)
}

function daysSince(iso) {
  return Math.floor((Date.now() - new Date(iso).getTime()) / 86400000)
}

const isMerchant = (u) => !!u.merchant_id || u.role?.startsWith('merchant')
const isProtected = (u) => u.id !== auth.user.id && u.abilities && u.abilities.update && !u.abilities.credentials

function badges(u) {
  const list = []
  if (u.status === 'suspended') list.push({ text: '⏸️ Suspendu', cls: 'bg-red-50 text-red-700' })
  else if (isMerchant(u)) list.push({ text: '● Actif', cls: 'text-emerald-700' })
  else if (!u.last_login_at) list.push({ text: 'jamais connecté', cls: 'bg-amber-50 text-amber-800' })
  else if (daysSince(u.last_login_at) >= (summary.value?.inactive_days ?? 30)) list.push({ text: `inactif depuis ${daysSince(u.last_login_at)} j`, cls: 'bg-amber-50 text-amber-800' })
  else list.push({ text: '● Actif', cls: 'text-emerald-700' })
  if (u.has_password === false && !isMerchant(u)) list.push({ text: 'sans mot de passe', cls: 'bg-slate-100 text-slate-600' })
  return list
}

function ficheLink(u) {
  if (u.courier?.id && auth.can('orders.dispatch')) return { path: '/admin/livreurs', query: { id: u.courier.id } }
  if (u.merchant_id && auth.can('merchants.view')) return { path: '/admin/marchands', query: { id: u.merchant_id, q: u.merchant?.business_name } }
  return null
}

function actions(u) {
  if (u.id === auth.user.id) return []
  const a = u.abilities || {}
  const list = []
  if (a.update && !isMerchant(u)) list.push({ key: 'edit', label: '✏️ Modifier' })
  const fiche = ficheLink(u)
  if (fiche) list.push({ key: 'fiche', label: u.courier ? '🛵 Fiche du livreur' : '🏪 Fiche de la boutique' })
  if (a.credentials && !isMerchant(u)) list.push({ key: 'invite', label: '💬 Envoyer un lien de connexion' })
  if (a.credentials) list.push({ key: 'logout', label: '📱 Déconnecter de tous ses appareils' })
  list.push({ key: 'history', label: '🕘 Historique du compte' })
  if (a.access && u.status === 'active') list.push({ key: 'suspend', label: '⏸️ Suspendre', danger: true })
  return list
}

function run(key, u) {
  menu.value = null
  if (key === 'edit') openForm(u)
  else if (key === 'fiche') router.push(ficheLink(u))
  else if (key === 'invite') invite(u)
  else if (key === 'history') openHistory(u)
  else ask(key, u)
}

function ask(kind, u) {
  const first = u.name.split(' ')[0]
  confirm.value = {
    suspend: { title: `Suspendre ${u.name} ?`, text: `${first} est déconnecté tout de suite de tous ses appareils et ne peut plus se connecter. Ses courses et son historique restent. Vous pourrez le réactiver à tout moment.`, button: 'Suspendre', danger: true, run: () => setStatus(u, 'suspended') },
    reactivate: { title: `Réactiver ${u.name} ?`, text: `${first} pourra de nouveau se connecter.`, button: 'Réactiver', run: () => setStatus(u, 'active') },
    logout: { title: `Déconnecter ${u.name} ?`, text: `${first} est déconnecté de tous ses téléphones et ordinateurs (téléphone perdu, appareil partagé…). Son compte reste actif : il pourra se reconnecter.`, button: 'Déconnecter partout', danger: true, run: () => logout(u) },
  }[kind]
}

async function act(fn, success) {
  busy.value = true
  try {
    const message = await fn()
    toasts.success(message || success)
    confirm.value = null
    load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

const setStatus = (u, status) => act(() => http.patch(`/users/${u.id}`, { status }).then(() => null), status === 'active' ? 'Compte réactivé.' : 'Compte suspendu.')
const logout = (u) => act(() => http.post(`/users/${u.id}/logout`).then((r) => r.data.message))

async function openHistory(u) {
  history.value = { user: u, loading: true, events: [] }
  try {
    const { data } = await http.get(`/users/${u.id}/history`)
    if (history.value?.user.id === u.id) history.value = { user: u, loading: false, events: data.data }
  } catch (e) {
    history.value = null
    toasts.error(apiErrorMessage(e))
  }
}

async function load() {
  loading.value = true
  try {
    const params = { scope: tab.value, search: search.value || undefined, role: tab.value === 'team' ? role.value || undefined : undefined, per_page: PER_PAGE, page: page.value }
    const [list, counters] = await Promise.all([http.get('/users', { params }), http.get('/users/summary').catch(() => null)])
    users.value = list.data.data
    meta.value = list.data.meta
    if (counters) summary.value = counters.data.data
    loadError.value = ''
  } catch (e) {
    loadError.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
  deletions.value = (await http.get('/account-deletions').catch(() => ({ data: { data: [] } }))).data.data
}

function reload() {
  page.value = 1
  load()
}

let timer = null
function debouncedLoad() {
  clearTimeout(timer)
  timer = setTimeout(reload, 300)
}

function setTab(key) {
  tab.value = key
  role.value = ''
  router.replace({ query: { ...route.query, onglet: key } })
  reload()
}

function go(delta) {
  page.value += delta
  load()
}

async function cancelDeletion(d) {
  if (!await confirmDialog(`Rouvrir le compte de ${d.name} ? Il pourra de nouveau se connecter.`)) return
  cancelling.value = d.id
  try {
    await http.post(`/account-deletions/${d.id}/cancel`)
    toasts.success('Compte rouvert.')
    load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    cancelling.value = null
  }
}

function openForm(user = null) {
  form.id = user?.id ?? null
  form.original = user ?? {}
  form.credentials = user ? !!user.abilities?.credentials : true
  form.access = user ? !!user.abilities?.access : true
  form.data = {
    name: user?.name ?? '',
    phone: user?.phone ?? '',
    email: user?.email ?? '',
    role: user?.role ?? (tab.value === 'couriers' ? 'courier' : 'dispatcher'),
    password: '',
  }
  showPassword.value = false
  form.error = ''
  form.open = true
}

/** Seuls les champs modifiés partent (sinon le serveur refuse, à raison, le téléphone d'un autre administrateur). */
function changes() {
  const o = form.original
  const d = form.data
  const out = {}
  if (d.name !== o.name) out.name = d.name
  if (form.credentials) {
    if (d.phone !== o.phone) out.phone = d.phone
    if ((d.email || null) !== (o.email || null)) out.email = d.email || null
    if (d.password) out.password = d.password
  }
  if (form.access && d.role !== o.role) out.role = d.role
  return out
}

async function save() {
  form.saving = true
  form.error = ''
  try {
    if (form.id) {
      const payload = changes()
      if (Object.keys(payload).length) await http.patch(`/users/${form.id}`, payload)
    } else {
      const payload = Object.fromEntries(Object.entries(form.data).filter(([, v]) => v !== ''))
      const { data } = await http.post('/users', payload)
      // Créé sans mot de passe : le lien d'invitation est prêt à envoyer
      if (data.invitation) invitation.value = { ...data.invitation, user: data.data }
    }
    form.open = false
    toasts.success('Utilisateur enregistré.')
    load()
  } catch (e) {
    form.error = apiErrorMessage(e)
  } finally {
    form.saving = false
  }
}

async function invite(user) {
  try {
    const { data } = await http.post(`/users/${user.id}/invitation`)
    invitation.value = { ...data.data, user }
  } catch (e) {
    toasts.error(apiErrorMessage(e, "L'invitation n'a pas pu être préparée."))
  }
}

async function copy(text) {
  try {
    await navigator.clipboard.writeText(text)
    toasts.success('Lien copié.')
  } catch {
    toasts.error('Copie impossible : sélectionnez le lien et copiez-le.')
  }
}

const closeMenu = () => { menu.value = null }

onMounted(async () => {
  document.addEventListener('click', closeMenu)
  load()
  assignable.value = (await http.get('/roles').catch(() => ({ data: { data: [] } }))).data.data
})
onBeforeUnmount(() => {
  document.removeEventListener('click', closeMenu)
  clearTimeout(timer)
})
</script>
