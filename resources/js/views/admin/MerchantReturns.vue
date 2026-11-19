<template>
  <div class="space-y-4">
    <h1 class="text-xl font-bold">Courses</h1>
    <!-- Courses, Activité des livreurs, Retours marchands -->
    <CoursesTabs />
    <div class="max-w-6xl">
      <p class="text-sm text-slate-600">
        Les colis à rendre aux e-commerçants : trop de tentatives, colis refusé, ou retour demandé par l'e-commerçant ou par l'agence.
        Regroupez ceux d'un e-commerçant sur un bon de retour confié à un livreur : l'e-commerçant est prévenu, il signe à la remise,
        et tous les colis passent « Retourné ».
      </p>
    </div>

    <!-- Vieux colis de la reprise : souvent plus réels, à clore en une fois -->
    <section v-if="legacy && legacy.total" class="card space-y-3 border-amber-300 bg-amber-50 p-4">
      <div class="flex flex-wrap items-start gap-3">
        <div class="min-w-64 flex-1">
          <h2 class="font-semibold text-amber-900">{{ legacy.total }} vieux colis de l'ancienne application {{ legacy.total > 1 ? 'sont' : 'est' }} à rendre</h2>
          <p class="text-sm text-amber-900">
            Repris de l'ancienne application, souvent notés « chez un livreur ». S'ils n'existent plus vraiment, closez-les comme
            retournés : ils sortent des retours et des courses en retard, sans toucher à l'argent. Annulable pendant 7 jours.
          </p>
        </div>
        <button type="button" class="btn-primary" :disabled="closing" @click="closeLegacy(null, legacy.total)">Tout clore comme retournés</button>
      </div>
      <ul class="divide-y divide-amber-200 rounded-lg bg-white text-sm">
        <li v-for="c in legacy.couriers" :key="c.courier_id ?? 0" class="flex flex-wrap items-center gap-3 px-3 py-2">
          <span class="min-w-40 flex-1 font-medium">{{ c.name }}</span>
          <span class="text-slate-600">{{ c.count }} colis<template v-if="c.oldest"> · prévus depuis le {{ shortDate(c.oldest) }}</template></span>
          <button type="button" class="btn-secondary" :disabled="closing" @click="closeLegacy(c.courier_id ?? 0, c.count, c.name)">{{ c.count > 1 ? `Clore ces ${c.count} colis` : 'Clore ce colis' }}</button>
        </li>
      </ul>
    </section>
    <p v-if="legacy?.last?.can_undo" class="rounded-lg bg-slate-100 p-3 text-sm text-slate-700">
      Dernière clôture : {{ legacy.last.done }} colis le {{ dateTime(legacy.last.finished_at) }}.
      <button type="button" class="font-semibold text-blue-700 underline" :disabled="closing" @click="undoLegacy">Annuler cette clôture</button>
    </p>

    <ReturnHandoverReview />

    <!-- Colis à rendre, par marchand -->
    <section class="space-y-3">
      <div class="flex flex-wrap items-center gap-2">
        <h2 class="flex-1 font-semibold">À rendre : {{ total }} colis · {{ candidates.length }} e-commerçant{{ candidates.length > 1 ? 's' : '' }}</h2>
        <input v-model="search" type="search" class="input w-full sm:w-64" placeholder="Chercher un e-commerçant" aria-label="Chercher un e-commerçant">
        <div class="flex flex-wrap gap-2" role="group" aria-label="Filtrer">
          <button
            v-for="f in FILTERS" :key="f.value" type="button" :aria-pressed="filter === f.value"
            :class="['min-h-10 rounded-full px-3.5 text-sm font-semibold ring-1', filter === f.value ? 'bg-slate-900 text-white ring-slate-900' : 'bg-white text-slate-900 ring-slate-300']"
            @click="filter = f.value"
          >
            {{ f.label }}
          </button>
        </div>
      </div>

      <p v-if="!loaded" class="card p-6 text-center text-slate-600">Chargement…</p>
      <p v-else-if="!visible.length" class="card p-6 text-center text-slate-600">{{ candidates.length ? 'Aucun e-commerçant ne correspond.' : 'Aucun colis à rendre aux e-commerçants.' }}</p>

      <div v-for="g in visible" :key="g.merchant.id" class="card">
        <!-- Ligne repliée : l'essentiel de l'e-commerçant -->
        <button type="button" class="flex w-full flex-wrap items-center gap-3 p-4 text-left hover:bg-slate-50" :aria-expanded="!!opened[g.merchant.id]" @click="toggle(g)">
          <span class="min-w-48 flex-1">
            <span class="block font-semibold">{{ g.merchant.business_name }}</span>
            <span class="block text-sm text-slate-600">{{ [g.merchant.zone, g.merchant.address].filter(Boolean).join(' · ') }}</span>
          </span>
          <span class="text-sm">
            <b class="tabular-nums">{{ g.total }}</b> colis
            <span class="text-slate-600"> · {{ g.total - g.held }} au dépôt · {{ g.held }} chez un livreur</span>
          </span>
          <span v-if="g.legacy" class="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-800 ring-1 ring-amber-200">{{ g.legacy }} de la reprise</span>
          <span v-if="g.oldest" :class="['text-xs', isOld(g.oldest) ? 'font-semibold text-red-700' : 'text-slate-600']">depuis le {{ shortDate(g.oldest) }}</span>
          <span class="text-sm font-semibold text-blue-700">{{ opened[g.merchant.id] ? 'Masquer' : 'Voir les colis' }}</span>
        </button>

        <!-- Dépliée : ses colis et le bon de retour -->
        <div v-if="opened[g.merchant.id]" class="border-t">
          <p v-if="!orders[g.merchant.id]" class="p-4 text-sm text-slate-600">Chargement…</p>
          <template v-else>
            <div class="flex flex-wrap items-center justify-end gap-2 border-b p-3">
              <span class="flex-1 text-sm text-slate-600">{{ g.merchant.phone }}</span>
              <SearchSelect
                v-model="courierFor[g.merchant.id]" input-class="input w-auto min-w-[12rem]" placeholder="Livreur…" :allow-empty="false" :empty-value="undefined"
                :aria-label="`Livreur pour ${g.merchant.business_name}`"
                :options="couriers.map((c) => ({ value: c.id, label: c.name, hint: `${holderCount(g, c.id) ? `a ${holderCount(g, c.id)} colis` : ''}${c.is_available ? '' : ' · indisponible'}`, search: c.phone }))"
              />
              <button class="btn-primary" :disabled="!selectedOf(g).length || !courierFor[g.merchant.id] || busy" @click="create(g)"><Icone nom="document" /> Créer le bon de retour ({{ selectedOf(g).length }})</button>
            </div>
            <div class="overflow-x-auto">
              <table class="w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs text-slate-700">
                  <tr>
                    <th class="w-8 p-2"><input type="checkbox" :checked="selectedOf(g).length === shownOrders(g).length && shownOrders(g).length > 0" aria-label="Tout cocher" @change="toggleAll(g, $event.target.checked)"></th>
                    <th class="p-2">Colis</th><th class="p-2">Destinataire</th><th class="p-2">État</th><th class="p-2">Où</th><th class="p-2 text-right">Montant</th>
                  </tr>
                </thead>
                <tbody class="divide-y">
                  <tr v-for="o in shownOrders(g)" :key="o.id">
                    <td class="w-8 p-2"><input v-model="selected" type="checkbox" :value="o.id" :aria-label="`Colis ${o.tracking_code}`"></td>
                    <td class="p-2">
                      <RouterLink :to="`/admin/courses/${o.id}`" class="font-mono text-blue-700">{{ o.tracking_code }}</RouterLink>
                      <span v-if="o.legacy" class="ml-1 rounded-full bg-amber-50 px-1.5 text-xs text-amber-800 ring-1 ring-amber-200">reprise</span>
                      <span class="block text-xs text-slate-600">prévu le {{ shortDate(o.delivery_day) }}</span>
                    </td>
                    <td class="p-2">{{ o.recipient_name || o.recipient_phone }} <span class="text-xs text-slate-600">{{ o.zone }}</span></td>
                    <td class="p-2 text-xs">{{ o.status_label }}<span v-if="o.incident"> · {{ o.incident }}</span><span v-if="o.return_courier" class="block text-slate-600">retour confié à {{ o.return_courier.name }}</span></td>
                    <td class="p-2 text-xs">
                      <span v-if="o.held_by" class="inline-flex items-center gap-1 text-amber-800"><Icone nom="en-main" taille="petite" /> chez {{ o.held_by.name }}</span>
                      <span v-else class="text-emerald-700">Au dépôt</span>
                    </td>
                    <td class="p-2 text-right tabular-nums">{{ money(o.items_amount) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </template>
        </div>
      </div>
    </section>

    <!-- Bons -->
    <section class="card">
      <h2 class="font-semibold p-4 border-b">Bons de retour (30 derniers jours)</h2>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-slate-50 text-left text-slate-600">
            <tr><th class="p-2">Bon</th><th class="p-2">Marchand</th><th class="p-2">Livreur</th><th class="p-2">Colis</th><th class="p-2">État</th><th /></tr>
          </thead>
          <tbody class="divide-y">
            <tr v-for="s in slips" :key="s.id">
              <td class="p-2 font-mono text-xs">{{ s.reference }}<div class="text-slate-600">{{ dateTime(s.created_at) }}</div></td>
              <td class="p-2">{{ s.merchant?.business_name }}</td>
              <td class="p-2">{{ s.courier?.name }}</td>
              <td class="p-2">{{ s.orders_count }}</td>
              <td class="p-2">
                <span :class="['rounded-full px-2 py-0.5 text-xs', s.status === 'handed' ? 'bg-emerald-100 text-emerald-800' : s.status === 'open' ? 'bg-amber-100 text-amber-900' : 'bg-slate-100 text-slate-600']">{{ s.status_label }}</span>
                <div v-if="s.handed_at" class="text-xs text-slate-600">{{ dateTime(s.handed_at) }} · {{ s.received_by_name }}</div>
              </td>
              <td class="p-2 text-right whitespace-nowrap space-x-1">
                <RouterLink :to="`/bon-de-retour/${s.id}`" class="btn-secondary"><Icone nom="imprimer" taille="petite" /> Voir / imprimer</RouterLink>
                <button v-if="s.status === 'open'" class="btn-secondary text-xs" @click="cancel(s)">Annuler</button>
              </td>
            </tr>
            <tr v-if="!slips.length"><td colspan="6" class="p-4 text-center text-slate-600">Aucun bon de retour.</td></tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</template>

<script setup>
import CoursesTabs from '../../components/CoursesTabs.vue'
import Icone from '../../components/ui/Icone.vue'
import ReturnHandoverReview from '../../components/admin/ReturnHandoverReview.vue'
import SearchSelect from '../../components/SearchSelect.vue'
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { useAuthStore } from '../../stores/auth'
import { useToastStore } from '../../stores/toasts'
import { dateTime, money } from '../../utils/format'
import { confirmDialog, promptDialog } from '../../composables/useDialog'

/**
 * Retours marchands : une ligne repliée par e-commerçant (combien, où, depuis quand) ; ses colis se chargent quand on la
 * déplie, et le bon de retour se crée de là. Les vieux colis de la reprise se closent en une fois (annulable).
 */
const FILTERS = [
  { value: 'all', label: 'Tous' },
  { value: 'hub', label: 'Au dépôt' },
  { value: 'held', label: 'Chez un livreur' },
  { value: 'old', label: 'Depuis plus de 7 jours' },
  { value: 'legacy', label: 'De la reprise' },
]
const OLD_DAYS = 7

const router = useRouter()
const auth = useAuthStore()
const toasts = useToastStore()
const candidates = ref([])
const total = ref(0)
const slips = ref([])
const couriers = ref([])
const selected = ref([])
const courierFor = reactive({})
const opened = reactive({})
const orders = reactive({})
const loaded = ref(false)
const busy = ref(false)
const search = ref('')
const filter = ref('all')
const legacy = ref(null)
const closing = ref(false)

const norm = (s) => (s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
const isOld = (day) => day && (Date.now() - new Date(`${day}T12:00:00`).getTime()) / 86400000 > OLD_DAYS
const shortDate = (day) => (day ? new Date(`${day}T12:00:00`).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short', year: 'numeric' }) : '')

const visible = computed(() => candidates.value.filter((g) => {
  if (search.value && !norm(g.merchant.business_name).includes(norm(search.value))) return false
  return {
    all: true,
    hub: g.total > g.held,
    held: g.held > 0,
    old: isOld(g.oldest),
    legacy: g.legacy > 0,
  }[filter.value]
}))
// Dans un e-commerçant déplié, le même filtre s'applique aux colis
function shownOrders(g) {
  return (orders[g.merchant.id] || []).filter((o) => ({
    all: true, hub: !o.held_by, held: !!o.held_by, old: isOld(o.delivery_day), legacy: o.legacy,
  }[filter.value]))
}
const selectedOf = (g) => shownOrders(g).filter((o) => selected.value.includes(o.id)).map((o) => o.id)
const holderCount = (g, courierId) => (orders[g.merchant.id] || []).filter((o) => o.held_by?.id === courierId).length

function toggleAll(g, on) {
  const ids = shownOrders(g).map((o) => o.id)
  selected.value = on ? [...new Set([...selected.value, ...ids])] : selected.value.filter((id) => !ids.includes(id))
}

async function toggle(g) {
  const id = g.merchant.id
  opened[id] = !opened[id]
  if (!opened[id] || orders[id]) return
  try {
    const list = (await http.get('/return-slips/candidates', { params: { merchant_id: id } })).data.data
    orders[id] = list
    // Par défaut : tous ses colis cochés, livreur qui en a déjà le plus en main
    selected.value = [...new Set([...selected.value, ...list.map((o) => o.id)])]
    const holders = list.filter((o) => o.held_by).map((o) => o.held_by.id)
    if (!courierFor[id] && holders.length) courierFor[id] = holders.sort((a, b) => holders.filter((x) => x === b).length - holders.filter((x) => x === a).length)[0]
  } catch (e) {
    opened[id] = false
    toasts.error(apiErrorMessage(e))
  }
}

async function load() {
  const { data } = await http.get('/return-slips')
  candidates.value = data.candidates
  total.value = data.candidates_total
  slips.value = data.data
  // Les listes dépliées sont rechargées à la demande
  for (const key of Object.keys(orders)) delete orders[key]
  for (const key of Object.keys(opened)) opened[key] = false
  loaded.value = true
  window.dispatchEvent(new Event('returns-changed'))
}

async function loadLegacy() {
  if (!auth.can('settings.manage')) return
  legacy.value = (await http.get('/company/legacy-purge/returns').catch(() => null))?.data?.data ?? null
}

async function closeLegacy(courierId, count, name = null) {
  const who = name ? ` (${name})` : ''
  const answer = await promptDialog(`Clore comme retournés ${count} vieux colis de la reprise${who} ? Ils sortent des retours et des courses en retard ; l'argent n'est pas touché. Annulable pendant 7 jours.\n\nTapez CLÔTURER pour confirmer.`)
  if (answer === null) return
  closing.value = true
  try {
    const { data } = await http.post('/company/legacy-purge/returns', { courier_id: courierId, confirm: answer.trim().toUpperCase() })
    toasts.success(`${data.data.done} colis clos comme retournés.`)
    await Promise.all([load(), loadLegacy()])
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    closing.value = false
  }
}

async function undoLegacy() {
  if (!await confirmDialog(`Annuler la clôture de ${legacy.value.last.done} colis ? Ils redeviennent « à rendre ».`)) return
  closing.value = true
  try {
    const { data } = await http.post(`/company/legacy-purge/${legacy.value.last.id}/undo`)
    toasts.success(`${data.data.restored} colis remis à rendre.`)
    await Promise.all([load(), loadLegacy()])
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    closing.value = false
  }
}

async function create(g) {
  busy.value = true
  try {
    const { data } = await http.post('/return-slips', { merchant_id: g.merchant.id, courier_id: courierFor[g.merchant.id], order_ids: selectedOf(g) })
    toasts.success(`Bon ${data.data.reference} créé : ${data.data.orders_count} colis. Le marchand est prévenu.`)
    router.push(`/bon-de-retour/${data.data.id}`)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

async function cancel(s) {
  if (!await confirmDialog(`Annuler le bon ${s.reference} ? Les missions de retour restent assignées.`)) return
  try {
    await http.post(`/return-slips/${s.id}/cancel`)
    load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

onMounted(async () => {
  load()
  loadLegacy()
  couriers.value = (await http.get('/couriers')).data.data
})
</script>
