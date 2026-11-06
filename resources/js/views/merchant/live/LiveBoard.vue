<template>
  <div class="space-y-3.5 pb-6">
    <p v-if="error" class="rounded-2xl bg-red-50 px-4 py-3 text-[15px] text-red-700" role="alert">{{ error }}</p>
    <p v-if="!b && !error" class="m-card p-6 text-center text-slate-500">Chargement…</p>

    <template v-if="b">
      <!-- En-tête -->
      <section class="rounded-[22px] bg-slate-900 p-4 text-white">
        <div class="flex items-start justify-between gap-2">
          <div>
            <span v-if="isLive" class="inline-flex items-center gap-2 rounded-full bg-rose-600 px-3 py-1 text-[13px] font-extrabold"><i class="block h-2 w-2 rounded-full bg-white" />EN LIVE · {{ elapsed }}</span>
            <span v-else class="inline-flex rounded-full bg-slate-700 px-3 py-1 text-[13px] font-bold">Live terminé</span>
            <p class="mt-1.5 text-[16px] font-bold">{{ b.live.name }}</p>
          </div>
          <button v-if="isLive" type="button" class="min-h-[44px] rounded-xl border border-slate-500 px-3 text-[15px] font-semibold" @click="end">Terminer</button>
        </div>
        <div class="mt-3 grid grid-cols-3 gap-2 text-center">
          <div class="rounded-xl bg-slate-800 py-2"><b class="block text-[22px]">{{ b.totals.orders }}</b><span class="text-[13px] text-slate-300">commandes</span></div>
          <div class="rounded-xl bg-slate-800 py-2"><b class="block text-[22px]">{{ short(b.totals.amount) }}</b><span class="text-[13px] text-slate-300">francs</span></div>
          <div class="rounded-xl bg-slate-800 py-2"><b class="block text-[22px]">{{ b.totals.waiting }}</b><span class="text-[13px] text-slate-300">en attente</span></div>
        </div>
      </section>

      <!-- Dernière commande, en grand -->
      <section v-if="latest" class="-mt-1 flex items-center gap-3 rounded-[20px] border-2 border-emerald-600 bg-white p-3.5 shadow-soft" role="status" aria-live="polite">
        <span class="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-emerald-100 text-emerald-800" aria-hidden="true">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M6 7h12l-1 13H7z" /><path d="M9 7a3 3 0 0 1 6 0" /></svg>
        </span>
        <div class="min-w-0">
          <p class="text-[13px] font-bold text-emerald-700">NOUVELLE COMMANDE</p>
          <p class="text-[18px] font-extrabold">{{ latest.customer_name }} a pris {{ latest.code }}</p>
          <p class="text-[14px] text-slate-600">{{ latest.name }} · Dites merci à {{ firstName(latest.customer_name) }} !</p>
        </div>
      </section>

      <div v-if="isLive" class="guide">
        <p class="guide-title">Étape 3 sur 3 · Pendant le live</p>
        <template v-if="b.whatsapp?.auto">
          <p>💬 Les commandes WhatsApp arrivent toutes seules.</p>
          <p>Vous présentez, les réponses partent toutes seules.</p>
        </template>
        <template v-else>
          <p>Gardez cet écran sur un 2e téléphone.</p>
          <p>Une commande ? Dites merci à la cliente.</p>
        </template>
      </div>
      <LiveWhatsAppConnect v-if="isLive && b.whatsapp && !b.whatsapp.auto" :initial="b.whatsapp" compact @connected="load" />

      <!-- Conseils (calculs) -->
      <section v-if="b.advices.length" class="m-card space-y-1.5 border-2 border-slate-900 p-4">
        <p class="text-[14px] font-extrabold uppercase tracking-wide">Conseils</p>
        <p v-for="a in b.advices" :key="a.text" :class="['text-[16px]', a.level === 'alert' ? 'font-bold text-orange-700' : a.level === 'good' ? 'text-emerald-700' : 'text-slate-900']">{{ a.text }}</p>
        <button v-if="b.advices.some((a) => a.level === 'alert')" type="button" class="mt-1 rounded-xl bg-slate-100 px-3 py-2 text-[15px] font-bold" @click="refreshCard">Carte à jour (produits épuisés barrés)</button>
      </section>

      <!-- Saisie d'une commande -->
      <form v-if="isLive" class="m-card space-y-3 p-4" @submit.prevent="addOrder">
        <p class="text-[16px] font-extrabold">{{ b.whatsapp?.auto ? 'Ajouter une commande à la main' : 'Nouvelle commande' }}</p>
        <!-- Un seul article : déjà choisi. Sinon, un bouton par code -->
        <p v-if="b.items.length === 1" class="rounded-xl bg-slate-100 px-3 py-2 text-[15px]"><b>{{ b.items[0].code }}</b> · {{ b.items[0].label || b.items[0].name }} · {{ money(b.items[0].price) }}</p>
        <div v-else role="radiogroup" aria-label="Code du produit" class="grid grid-cols-3 gap-2">
          <button
            v-for="i in b.items" :key="i.id" type="button" role="radio" :aria-checked="form.code === i.code"
            :class="['min-h-[52px] rounded-xl px-2 py-1.5 text-left leading-tight ring-1', form.code === i.code ? 'bg-slate-900 text-white ring-slate-900' : 'bg-white ring-slate-300', i.remaining ? '' : 'opacity-50']"
            @click="form.code = i.code"
          >
            <b class="block text-[17px]">{{ i.code }}</b>
            <span class="block truncate text-[12px]">{{ i.variant || i.name }}{{ i.remaining ? '' : ' · épuisé' }}</span>
          </button>
        </div>
        <label class="block w-28 text-[14px] font-semibold text-slate-600">Quantité
          <input v-model.number="form.quantity" type="number" min="1" class="m-input mt-1 w-full" required>
        </label>
        <div class="grid grid-cols-2 gap-2">
          <label class="block text-[14px] font-semibold text-slate-600">Nom
            <input v-model="form.recipient_name" class="m-input mt-1 w-full" required>
          </label>
          <label class="block text-[14px] font-semibold text-slate-600">Numéro
            <input v-model="form.recipient_phone" type="tel" inputmode="tel" class="m-input mt-1 w-full" required>
          </label>
        </div>
        <label class="block text-[14px] font-semibold text-slate-600" for="live-zone">Quartier</label>
        <ZoneSelect id="live-zone" v-model="form.delivery_zone_id" :zones="zones" mobile input-class="m-input" required />
        <button class="m-btn-primary w-full" :disabled="saving || !form.code">{{ saving ? 'Enregistrement…' : 'Ajouter la commande' }}</button>
        <p class="text-[13px] text-slate-500">La course part à votre agence. Produit épuisé : la cliente passe en liste d'attente.</p>
      </form>

      <!-- Produits -->
      <section class="m-card divide-y divide-slate-100">
        <p class="px-4 py-2.5 text-[13px] font-semibold text-slate-500">MES PRODUITS</p>
        <p v-if="productGroups.length > 1" class="px-4 py-2 text-[14px] text-slate-600">Vous présentez un produit ? Touchez 🖼️ pour sa carte en grand.</p>
        <div v-for="i in b.items" :key="i.id" :class="['flex items-center gap-3 px-4 py-2.5', i.remaining ? '' : 'opacity-60']">
          <span class="grid h-9 min-w-[44px] place-items-center rounded-lg bg-slate-900 px-1.5 text-[14px] font-extrabold text-white">{{ i.code }}</span>
          <div class="min-w-0 flex-1">
            <p :class="['truncate text-[15px] font-bold', i.remaining ? '' : 'line-through']">{{ i.label || i.name }} <span class="font-normal text-slate-500">· {{ money(i.price) }}</span></p>
            <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-slate-100"><i class="block h-full bg-rose-600" :style="{ width: `${Math.min(100, (i.sold / Math.max(1, i.quantity)) * 100)}%` }" /></div>
          </div>
          <span :class="['shrink-0 text-[14px] font-bold', !i.remaining ? 'text-red-700' : i.remaining <= 3 ? 'text-orange-700' : 'text-slate-600']">{{ i.remaining ? `${i.remaining} ${i.remaining > 1 ? 'restent' : 'reste'}` : 'ÉPUISÉ' }}</span>
          <button
            v-if="productGroups.length > 1 && firstOfProduct(i)" type="button"
            class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-slate-100 text-[18px]"
            :aria-label="`Carte du produit en cours : ${i.name}`" @click="productCard(i)"
          >🖼️</button>
        </div>
      </section>

      <!-- Commandes -->
      <section class="m-card divide-y divide-slate-100">
        <p class="px-4 py-2.5 text-[13px] font-semibold text-slate-500">COMMANDES · {{ b.orders.length }}</p>
        <div v-for="o in b.orders" :key="o.id" class="px-4 py-2.5 text-[15px]">
          <div class="flex items-start gap-2">
            <span :class="['mt-0.5 shrink-0 rounded-md px-1.5 py-0.5 text-[11px] font-extrabold', o.status === 'waiting' ? 'bg-orange-100 text-orange-800' : 'bg-emerald-100 text-emerald-800']">{{ o.status === 'waiting' ? 'ATTENTE' : 'COMMANDE' }}</span>
            <span v-if="o.source === 'whatsapp'" class="mt-0.5 shrink-0 rounded-md bg-[#dcfce7] px-1.5 py-0.5 text-[11px] font-extrabold text-[#128c4b]" title="Commande reçue par WhatsApp, enregistrée toute seule">💬 AUTO</span>
            <div class="min-w-0 flex-1">
              <b>{{ o.customer_name }}</b> · {{ o.code }} {{ o.name }}<template v-if="o.quantity > 1"> × {{ o.quantity }}</template>
              <span class="block text-[13px] text-slate-500">{{ localPhone(o.customer_phone) }} · {{ time(o.created_at) }}</span>
              <span v-if="o.refusals" class="mt-1 block rounded-lg bg-red-50 px-2 py-1 text-[13px] text-red-800"><b>Attention :</b> {{ o.refusals }} colis refusés. Demandez un acompte.</span>
            </div>
            <RouterLink v-if="o.order_id" :to="`/marchand/courses/${o.order_id}`" class="shrink-0 text-[14px] font-semibold text-[var(--app-color)]">Course ›</RouterLink>
          </div>
        </div>
        <p v-if="!b.orders.length" class="p-5 text-center text-[15px] text-slate-500">Les commandes apparaîtront ici.</p>
      </section>

      <RouterLink v-if="!isLive" :to="`/marchand/live/${b.live.id}/bilan`" class="m-btn-primary w-full">Voir le bilan</RouterLink>
    </template>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../../../bootstrap/axios'
import ZoneSelect from '../../../components/ZoneSelect.vue'
import { useToastStore } from '../../../stores/toasts'
import { localPhone, money } from '../../../utils/format'
import { drawCard, saveCard } from './liveCard'
import LiveWhatsAppConnect from './LiveWhatsAppConnect.vue'
import { confirmDialog } from '../../../composables/useDialog'

/** Écran du live : commandes en direct, conseils, produits, saisie rapide */
const route = useRoute()
const router = useRouter()
const toasts = useToastStore()
const b = ref(null)
const error = ref('')
const zones = ref([])
const saving = ref(false)
const now = ref(Date.now())
const form = reactive({ code: '', quantity: 1, recipient_name: '', recipient_phone: '', delivery_zone_id: null })
let timer = null
let clock = null

const isLive = computed(() => b.value?.live.status === 'live')
const latest = computed(() => {
  const o = b.value?.orders.find((x) => x.status === 'ordered')
  return o && Date.now() - new Date(o.created_at).getTime() < 10 * 60000 ? o : null
})
const elapsed = computed(() => {
  const s = Math.max(0, Math.floor((now.value - new Date(b.value.live.started_at).getTime()) / 1000))
  return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`
})
const short = (v) => Number(v || 0).toLocaleString('fr-FR')
const time = (iso) => new Date(iso).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
const firstName = (n) => (n || '').split(/\s+/)[0]

// Produits du live (les tailles d'un même produit ensemble)
const productGroups = computed(() => {
  const groups = new Map()
  for (const i of b.value?.items || []) groups.set(i.product_id ?? i.id, [...(groups.get(i.product_id ?? i.id) || []), i])
  return [...groups.values()]
})
const firstOfProduct = (i) => productGroups.value.some((g) => g[0].id === i.id)

async function load() {
  try {
    b.value = (await http.get(`/live/${route.params.id}`)).data.data
    // Un seul article : il est déjà choisi
    if (b.value.items.length === 1) form.code = b.value.items[0].code
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

async function addOrder() {
  saving.value = true
  try {
    b.value = (await http.post(`/live/${route.params.id}/orders`, form)).data.data
    const last = b.value.orders[0]
    toasts.success(last.status === 'waiting' ? `${last.code} épuisé : ${last.customer_name} est en liste d'attente.` : `Commande de ${last.customer_name} enregistrée.`)
    Object.assign(form, { quantity: 1, recipient_name: '', recipient_phone: '', delivery_zone_id: null })
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    saving.value = false
  }
}

async function refreshCard() {
  try {
    const groups = {}
    for (const i of b.value.items) (groups[i.code[0]] ||= []).push(i)
    for (const letter of Object.keys(groups)) {
      const canvas = await drawCard({ items: groups[letter] })
      await saveCard(canvas, `live-carte-${letter}.png`)
    }
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

// Carte du produit en cours : ce produit seul, en grand (avec ses tailles)
async function productCard(item) {
  try {
    const items = productGroups.value.find((g) => g[0].id === item.id)
    const canvas = await drawCard({ items })
    await saveCard(canvas, `live-${item.code}.png`)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function end() {
  if (!await confirmDialog('Terminer le live ?')) return
  try {
    await http.post(`/live/${route.params.id}/end`)
    router.push(`/marchand/live/${route.params.id}/bilan`)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

onMounted(async () => {
  await load()
  zones.value = (await http.get('/zones')).data.data
  timer = setInterval(() => { if (isLive.value && !saving.value) load() }, 8000)
  clock = setInterval(() => { now.value = Date.now() }, 1000)
})
onBeforeUnmount(() => {
  clearInterval(timer)
  clearInterval(clock)
})
</script>

<style scoped>
.guide {
  border-radius: 18px;
  background: #1e40af;
  color: #fff;
  padding: 14px 16px;
  font-size: 18px;
  font-weight: 700;
  line-height: 1.35;
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.guide-title {
  font-size: 14px;
  font-weight: 800;
  color: #bfdbfe;
  text-transform: uppercase;
  letter-spacing: .04em;
}
</style>
