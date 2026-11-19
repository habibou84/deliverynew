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
      <div class="flex justify-center text-slate-400"><Icone nom="relais" taille="grande" class="!h-12 !w-12" /></div>
      <p>{{ tab === 'receive' ? 'Aucun colis à recevoir d\'un autre livreur.' : 'Aucun colis à remettre à un autre livreur.' }}</p>
    </div>

    <!-- À recevoir : saisir le code montré par l'autre livreur, cocher les colis reçus -->
    <template v-if="tab === 'receive'">
      <section v-for="g in groups" :key="g.courier_id" class="m-card p-4 space-y-3">
        <div>
          <p class="text-xs font-semibold uppercase tracking-wide text-[var(--app-color)]">À recevoir de</p>
          <p class="text-lg font-bold">{{ g.name }}</p>
          <p class="text-sm text-slate-600">{{ g.parcels.length }} colis · {{ g.relay ? 'QR code prêt chez lui' : 'demandez-lui d\'afficher son QR code' }}</p>
        </div>
        <ContactActions :phone="g.phone" :maps-href="g.lat ? mapsLink(g.lat, g.lng, '') : null" maps-label="Le rejoindre" />

        <div class="divide-y rounded-xl border">
          <label v-for="o in g.parcels" :key="o.id" class="flex items-center gap-3 p-3">
            <input v-model="checked[g.courier_id]" type="checkbox" :value="o.id" class="h-5 w-5">
            <span class="flex-1 min-w-0">
              <span class="block font-medium truncate">{{ o.recipient_name || o.tracking_code }}</span>
              <span class="block text-xs text-slate-600">{{ o.tracking_code }} · {{ o.is_return ? `retour à ${o.merchant}` : `${o.zone} · ${money(o.cod_amount)}` }}</span>
            </span>
          </label>
        </div>
        <p v-if="(checked[g.courier_id] || []).length < g.parcels.length" class="text-sm text-amber-700">Les colis non cochés restent chez {{ g.name }} : l'agence est prévenue.</p>

        <template v-if="g.relay">
          <p v-if="errors[g.courier_id]" class="text-sm text-red-600">{{ errors[g.courier_id] }}</p>
          <!-- QR code de l'autre livreur : scanné, les colis cochés sont reçus -->
          <button class="m-btn-primary" :disabled="busy || !(checked[g.courier_id] || []).length" @click="scanning = g">
            <span class="inline-flex items-center justify-center gap-2"><Icone nom="scanner" />Scanner le QR code de {{ g.name }} ({{ (checked[g.courier_id] || []).length }} colis)</span>
          </button>
          <button v-if="!typing[g.courier_id]" type="button" class="w-full text-center text-sm font-medium text-[var(--app-color)] underline" @click="typing[g.courier_id] = true">
            Caméra en panne ? Saisir le code à la place
          </button>
          <template v-else>
            <label class="m-label" :for="`code-${g.courier_id}`">Code à 4 chiffres affiché sous le QR code de {{ g.name }}</label>
            <input :id="`code-${g.courier_id}`" v-model="codes[g.courier_id]" class="m-input text-center text-3xl font-bold tracking-[0.5em]" inputmode="numeric" maxlength="4" autocomplete="one-time-code" placeholder="••••">
            <button class="m-btn-secondary" :disabled="busy || (codes[g.courier_id] || '').length !== 4 || !(checked[g.courier_id] || []).length" @click="confirm(g)">
              <span class="inline-flex items-center justify-center gap-2"><Icone nom="valider" />J'ai reçu {{ (checked[g.courier_id] || []).length }} colis</span>
            </button>
          </template>
        </template>
        <p v-else class="flex items-start gap-2 rounded-xl bg-slate-50 p-3 text-sm text-slate-600"><Icone nom="attente" taille="petite" class="mt-0.5" />Quand {{ g.name }} affichera son QR code, le bouton pour le scanner apparaîtra ici.</p>
      </section>
    </template>

    <!-- À remettre : choisir les colis et afficher le code à montrer -->
    <template v-else>
      <section v-for="g in groups" :key="g.courier_id" class="m-card p-4 space-y-3">
        <div>
          <p class="text-xs font-semibold uppercase tracking-wide text-[var(--app-color)]">À remettre à</p>
          <p class="text-lg font-bold">{{ g.name }}</p>
          <p class="text-sm text-slate-600">{{ g.parcels.length }} colis : c'est ce livreur qui part avec</p>
        </div>
        <ContactActions :phone="g.phone" :maps-href="g.lat ? mapsLink(g.lat, g.lng, '') : null" maps-label="Le rejoindre" />

        <div v-if="g.relay" class="rounded-2xl bg-[var(--app-color)] p-5 text-center text-white space-y-2">
          <p class="text-sm text-white/80">{{ g.name }} scanne ce QR code avec son téléphone</p>
          <img v-if="qr[`${g.relay.id}:${g.relay.code}`]" :src="qr[`${g.relay.id}:${g.relay.code}`]" :alt="`QR code du relais pour ${g.name}`" class="mx-auto h-56 w-56 rounded-xl bg-white p-2">
          <p class="text-xs text-white/80">{{ g.relay.order_ids.length }} colis · valable jusqu'à {{ time(g.relay.expires_at) }}</p>
          <p class="text-xs text-white/80">Caméra en panne chez lui ? Code : <strong class="text-base tracking-[0.2em] text-white">{{ g.relay.code }}</strong></p>
        </div>

        <div class="divide-y rounded-xl border">
          <label v-for="o in g.parcels" :key="o.id" class="flex items-center gap-3 p-3">
            <input v-model="checked[g.courier_id]" type="checkbox" :value="o.id" class="h-5 w-5">
            <span class="flex-1 min-w-0">
              <span class="block font-medium truncate">{{ o.recipient_name || o.tracking_code }}</span>
              <span class="block text-xs text-slate-600">{{ o.tracking_code }} · {{ o.is_return ? `retour à ${o.merchant}` : `${o.merchant} · ${o.zone}` }}</span>
            </span>
          </label>
        </div>
        <p v-if="errors[g.courier_id]" class="text-sm text-red-600">{{ errors[g.courier_id] }}</p>
        <button :class="g.relay ? 'm-btn-secondary' : 'm-btn-primary'" :disabled="busy || !(checked[g.courier_id] || []).length" @click="offer(g)">
          <span class="inline-flex items-center justify-center gap-2"><Icone :nom="g.relay ? 'actualiser' : 'qr-code'" />{{ g.relay ? 'Nouveau QR code' : `Afficher le QR code (${(checked[g.courier_id] || []).length} colis)` }}</span>
        </button>
      </section>
    </template>

    <QrScanner v-if="scanning" :title="`QR code de ${scanning.name}`" @read="onScan" @close="scanning = null" />
  </div>
</template>

<script setup>
import QRCode from 'qrcode'
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import QrScanner from '../../components/courier/QrScanner.vue'
import ContactActions from '../../components/courier/ContactActions.vue'
import Icone from '../../components/ui/Icone.vue'
import { currentPosition } from '../../composables/useGeolocation'
import { useToastStore } from '../../stores/toasts'
import { mapsLink, money } from '../../utils/format'

/**
 * Relais entre livreurs : celui qui a les colis affiche un QR code (et son code à 4 chiffres en secours), celui qui
 * doit les livrer coche les colis reçus et le scanne avec la caméra. Obligatoire avant « Je pars livrer » quand le colis est chez un autre livreur.
 */
const toasts = useToastStore()
const board = ref({ give: [], receive: [] })
const tab = ref('receive')
const loading = ref(true)
const error = ref('')
const busy = ref(false)
const checked = reactive({})
const codes = reactive({})
const errors = reactive({})
// Saisie du code à la main (caméra en panne), QR codes affichés, relais en cours de lecture
const typing = reactive({})
const qr = reactive({})
const scanning = ref(null)
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

// QR code du relais : préfixe, numéro du relais, code (le serveur vérifie le code comme s'il était saisi)
watch(() => board.value.give.map((g) => g.relay && `${g.relay.id}:${g.relay.code}`).join(','), async () => {
  for (const g of board.value.give) {
    const key = g.relay && `${g.relay.id}:${g.relay.code}`
    if (key && !qr[key]) qr[key] = await QRCode.toDataURL(`${QR_PREFIX}:${g.relay.id}:${g.relay.code}`, { width: 448, margin: 1, errorCorrectionLevel: 'M' })
  }
})

function onScan(text) {
  const g = scanning.value
  scanning.value = null
  const [prefix, id, code] = String(text).split(':')
  if (prefix !== QR_PREFIX || !g?.relay) {
    errors[g.courier_id] = 'Ce n\'est pas un QR code de relais Jibiat.'
    return
  }
  if (Number(id) !== g.relay.id) {
    errors[g.courier_id] = `Ce QR code n'est pas celui de ${g.name} (ou il a été renouvelé) : scannez celui affiché maintenant.`
    return
  }
  codes[g.courier_id] = code
  confirm(g)
}

async function confirm(g) {
  busy.value = true
  errors[g.courier_id] = ''
  try {
    const position = await currentPosition(3000).catch(() => null)
    const { data } = await http.post(`/courier/relays/${g.relay.id}/confirm`, { code: codes[g.courier_id], order_ids: checked[g.courier_id], ...(position || {}) })
    toasts.success(`${data.data.received} colis reçus de ${g.name}. Vous pouvez partir livrer.`)
    codes[g.courier_id] = ''
    await load()
  } catch (e) {
    errors[g.courier_id] = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}

// L'autre livreur avance de son côté (code affiché, colis reçus) : écran mis à jour tout seul
let timer = null
onMounted(() => {
  load(true)
  timer = setInterval(() => !document.hidden && !busy.value && load(), 8000)
})
onBeforeUnmount(() => clearInterval(timer))
</script>
