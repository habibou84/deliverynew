<template>
  <div class="space-y-4 pb-10">
    <div class="flex rounded-xl bg-slate-200 p-1">
      <button v-for="t in tabs" :key="t.value" :class="['tap flex-1 rounded-lg py-2.5 text-sm font-semibold', tab === t.value ? 'bg-white shadow-sm text-[var(--app-color)]' : 'text-slate-600']" @click="tab = t.value">
        {{ t.label }}
        <span :class="['ml-1 rounded-full px-1.5 text-xs', parcels(t.value) ? 'bg-[var(--app-color)] text-white' : 'bg-slate-300 text-slate-600']">{{ parcels(t.value) }}</span>
      </button>
    </div>

    <p v-if="loading" class="text-center text-slate-600 py-10">Chargement…</p>
    <p v-else-if="error" class="text-center text-red-600 py-8">{{ error }}</p>
    <div v-else-if="!groups.length" class="text-center text-slate-600 py-12 space-y-1">
      <p class="text-4xl">🤝</p>
      <p>{{ tab === 'receive' ? 'Aucun colis à recevoir d\'un autre livreur.' : 'Aucun colis à remettre à un autre livreur.' }}</p>
    </div>

    <!-- À recevoir : cocher les colis reçus, scanner le QR code montré par l'autre livreur -->
    <template v-if="tab === 'receive'">
      <section v-for="g in groups" :key="g.courier_id" class="m-card p-4 space-y-3">
        <div>
          <p class="text-xs font-semibold uppercase tracking-wide text-[var(--app-color)]">À recevoir de</p>
          <p class="text-lg font-bold">{{ g.name }}</p>
          <p class="text-sm text-slate-600">{{ g.parcels.length }} colis · {{ g.relay ? 'QR code prêt chez lui' : 'demandez-lui d\'afficher le QR code' }}</p>
        </div>
        <ContactButtons :courier="g" />

        <div class="divide-y rounded-xl border">
          <label v-for="o in g.parcels" :key="o.id" class="flex items-center gap-3 p-3">
            <input v-model="checked[g.courier_id]" type="checkbox" :value="o.id" class="h-5 w-5">
            <span class="flex-1 min-w-0">
              <span class="block font-medium truncate">{{ o.recipient_name || o.tracking_code }}</span>
              <span class="block text-xs text-slate-600">{{ o.tracking_code }} · {{ o.is_return ? `↩️ retour à ${o.merchant}` : `${o.zone} · ${money(o.cod_amount)}` }}</span>
            </span>
          </label>
        </div>
        <p v-if="(checked[g.courier_id] || []).length < g.parcels.length" class="text-sm text-amber-700">Les colis non cochés restent chez {{ g.name }} : l'agence est prévenue.</p>

        <p v-if="errors[g.courier_id]" class="text-sm text-red-600" role="alert">{{ errors[g.courier_id] }}</p>
        <button class="m-btn-primary" :disabled="busy || !(checked[g.courier_id] || []).length" @click="scanning = g">
          📷 Scanner le QR code ({{ (checked[g.courier_id] || []).length }} colis reçus)
        </button>
        <p v-if="!g.relay" class="rounded-xl bg-slate-50 p-3 text-sm text-slate-600">⏳ Demandez à {{ g.name }} d'ouvrir Relais › À remettre et d'afficher le QR code, puis scannez-le.</p>
      </section>
    </template>

    <!-- À remettre : choisir les colis et afficher le QR code à faire scanner -->
    <template v-else>
      <section v-for="g in groups" :key="g.courier_id" class="m-card p-4 space-y-3">
        <div>
          <p class="text-xs font-semibold uppercase tracking-wide text-[var(--app-color)]">À remettre à</p>
          <p class="text-lg font-bold">{{ g.name }}</p>
          <p class="text-sm text-slate-600">{{ g.parcels.length }} colis : c'est ce livreur qui part avec</p>
        </div>
        <ContactButtons :courier="g" />

        <div v-if="g.relay" class="rounded-2xl bg-[var(--app-color)] p-5 text-center text-white space-y-2">
          <p class="text-sm text-white/90">Faites scanner ce QR code par {{ g.name }}</p>
          <img v-if="qrImages[g.relay.id]" :src="qrImages[g.relay.id]" :alt="`QR code du relais à faire scanner par ${g.name}`" class="mx-auto h-64 w-64 rounded-xl bg-white p-2">
          <p class="text-xs text-white/80">{{ g.relay.order_ids.length }} colis · valable jusqu'à {{ time(g.relay.expires_at) }}</p>
        </div>

        <div class="divide-y rounded-xl border">
          <label v-for="o in g.parcels" :key="o.id" class="flex items-center gap-3 p-3">
            <input v-model="checked[g.courier_id]" type="checkbox" :value="o.id" class="h-5 w-5">
            <span class="flex-1 min-w-0">
              <span class="block font-medium truncate">{{ o.recipient_name || o.tracking_code }}</span>
              <span class="block text-xs text-slate-600">{{ o.tracking_code }} · {{ o.is_return ? `↩️ retour à ${o.merchant}` : `${o.merchant} · ${o.zone}` }}</span>
            </span>
          </label>
        </div>
        <p v-if="errors[g.courier_id]" class="text-sm text-red-600">{{ errors[g.courier_id] }}</p>
        <button :class="g.relay ? 'm-btn-secondary' : 'm-btn-primary'" :disabled="busy || !(checked[g.courier_id] || []).length" @click="offer(g)">
          {{ g.relay ? '🔄 Nouveau QR code' : `🔳 Afficher le QR code (${(checked[g.courier_id] || []).length} colis)` }}
        </button>
      </section>
    </template>

    <QrScanner v-if="scanning" :title="`QR code de ${scanning.name}`" hint="Placez le QR code affiché sur son téléphone dans le cadre." @detected="scanned(scanning, $event)" @close="scanning = null" />
  </div>
</template>

<script setup>
import QRCode from 'qrcode'
import { computed, defineComponent, h, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import QrScanner from '../../components/mobile/QrScanner.vue'
import { currentPosition } from '../../composables/useGeolocation'
import { useToastStore } from '../../stores/toasts'
import { mapsLink, money, telLink, whatsappLink } from '../../utils/format'

/**
 * Relais entre livreurs : celui qui a les colis affiche un QR code, celui qui doit les livrer coche les colis reçus
 * et le scanne. Obligatoire avant « Je pars livrer » quand le colis est chez un autre livreur.
 */
const ContactButtons = defineComponent({
  props: { courier: { type: Object, required: true } },
  setup(props) {
    const btn = 'tap rounded-2xl bg-slate-100 py-3 flex flex-col items-center gap-1 text-sm font-medium active:bg-slate-200'
    return () => h('div', { class: 'grid grid-cols-3 gap-2' }, [
      h('a', { href: telLink(props.courier.phone), class: btn }, [h('span', { class: 'text-2xl' }, '📞'), 'Appeler']),
      h('a', { href: whatsappLink(props.courier.phone), target: '_blank', class: btn }, [h('span', { class: 'text-2xl' }, '💬'), 'WhatsApp']),
      props.courier.lat
        ? h('a', { href: mapsLink(props.courier.lat, props.courier.lng, ''), target: '_blank', class: btn }, [h('span', { class: 'text-2xl' }, '🗺️'), 'Le rejoindre'])
        : h('span', { class: `${btn} opacity-40` }, [h('span', { class: 'text-2xl' }, '🗺️'), 'Position ?']),
    ])
  },
})

const toasts = useToastStore()
const board = ref({ give: [], receive: [] })
const tab = ref('receive')
const loading = ref(true)
const error = ref('')
const busy = ref(false)
const checked = reactive({})
const qrImages = reactive({})
const scanning = ref(null)
const errors = reactive({})
const QR_PREFIX = 'JIBIAT-RELAIS'
const tabs = [{ value: 'receive', label: 'À recevoir' }, { value: 'give', label: 'À remettre' }]

const groups = computed(() => board.value[tab.value] || [])
const parcels = (t) => (board.value[t] || []).reduce((n, g) => n + g.parcels.length, 0)
const time = (v) => new Date(v).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })

async function load(first = false) {
  try {
    board.value = (await http.get('/courier/relays')).data.data
    // Tous les colis cochés par défaut (on garde les choix déjà faits)
    for (const g of [...board.value.give, ...board.value.receive]) {
      const ids = g.parcels.map((o) => o.id)
      checked[g.courier_id] = checked[g.courier_id]?.filter((id) => ids.includes(id)) ?? ids
      if (!checked[g.courier_id].length) checked[g.courier_id] = ids
    }
    if (first && !parcels('receive') && parcels('give')) tab.value = 'give'
    // QR codes à montrer : « JIBIAT-RELAIS:<relais>:<jeton> »
    for (const g of board.value.give) {
      if (g.relay?.code && !qrImages[g.relay.id]) {
        qrImages[g.relay.id] = await QRCode.toDataURL(`${QR_PREFIX}:${g.relay.id}:${g.relay.code}`, { margin: 1, width: 512, errorCorrectionLevel: 'M' })
      }
    }
    error.value = ''
  } catch (e) {
    error.value = apiErrorMessage(e, 'Impossible de charger les relais.')
  } finally {
    loading.value = false
  }
}

async function offer(g) {
  busy.value = true
  errors[g.courier_id] = ''
  try {
    const position = await currentPosition(3000).catch(() => null)
    await http.post('/courier/relays', { to_courier_id: g.courier_id, order_ids: checked[g.courier_id], ...(position || {}) })
    await load()
  } catch (e) {
    errors[g.courier_id] = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}

// QR code lu : on vérifie qu'il vient bien du livreur choisi, puis les colis cochés passent chez moi
async function scanned(g, text) {
  scanning.value = null
  errors[g.courier_id] = ''
  const match = /^JIBIAT-RELAIS:(\d+):([A-Za-z0-9]+)$/.exec((text || '').trim())
  if (!match) {
    errors[g.courier_id] = 'Ce n\'est pas un QR code de relais. Scannez celui affiché dans Relais sur le téléphone de l\'autre livreur.'
    return
  }
  const relayId = Number(match[1])
  busy.value = true
  try {
    // Relais pas encore vu ici (affiché il y a quelques secondes) : on recharge
    if (g.relay?.id !== relayId) await load()
    const owner = board.value.receive.find((r) => r.relay?.id === relayId)
    const current = board.value.receive.find((r) => r.courier_id === g.courier_id)
    if (!owner) {
      errors[g.courier_id] = 'QR code expiré ou remplacé : demandez à l\'autre livreur d\'afficher un nouveau QR code.'
      return
    }
    if (owner.courier_id !== g.courier_id) {
      errors[g.courier_id] = `Ce QR code est celui de ${owner.name}, pas de ${g.name}.`
      return
    }
    const position = await currentPosition(3000).catch(() => null)
    const { data } = await http.post(`/courier/relays/${relayId}/confirm`, { code: match[2], order_ids: checked[g.courier_id], ...(position || {}) })
    toasts.success(`🤝 ${data.data.received} colis reçus de ${current?.name || g.name}. Vous pouvez partir livrer.`)
    await load()
  } catch (e) {
    errors[g.courier_id] = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}

// L'autre livreur avance de son côté (QR code affiché, colis reçus) : écran mis à jour tout seul
let timer = null
onMounted(() => {
  load(true)
  timer = setInterval(() => !document.hidden && !busy.value && !scanning.value && load(), 8000)
})
onBeforeUnmount(() => clearInterval(timer))
</script>
