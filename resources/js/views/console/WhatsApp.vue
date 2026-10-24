<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h1 class="text-xl font-bold">WhatsApp de Jibiat</h1>
      <span v-if="state" :class="['rounded-full px-2 py-0.5 text-xs font-medium', STATUS[state.status]?.[1]]">
        {{ STATUS[state.status]?.[0] }}<template v-if="state.status === 'connected'"> · {{ state.channel === 'official' ? 'connexion officielle' : 'QR code' }}</template>
      </span>
    </div>

    <!-- Connexion officielle (API Cloud de Meta), saisie à la main -->
    <section v-if="state" class="card p-4 space-y-3">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <h2 class="font-semibold">Connexion officielle (Meta)</h2>
        <span v-if="state.official" :class="['rounded-full px-2 py-0.5 text-xs font-medium', OFFICIAL[state.official.status]?.[1] || 'bg-slate-100 text-slate-700']">{{ OFFICIAL[state.official.status]?.[0] || state.official.status }}</span>
      </div>
      <p class="text-sm text-gray-600">
        Le numéro de Jibiat sur l'API WhatsApp Business de Meta. Une fois vérifiée, elle est utilisée en priorité pour la messagerie
        de la console et les codes de vérification ; le QR code reste en secours.
      </p>
      <p v-if="state.official?.live" class="text-sm text-emerald-800">
        ✅ Numéro <strong>{{ state.official.display_phone }}</strong><span v-if="state.official.verified_name"> ({{ state.official.verified_name }})</span> vérifié par Meta<span v-if="state.official.quality_rating"> · qualité {{ QUALITY[state.official.quality_rating] || state.official.quality_rating }}</span>.
        <span v-if="!state.official.webhook_subscribed" class="text-amber-700">Abonnement aux messages reçus à vérifier.</span>
      </p>
      <p v-if="state.official?.error" class="rounded-lg bg-red-50 p-3 text-sm text-red-800">{{ state.official.error }}</p>

      <form class="grid gap-3 md:grid-cols-2" @submit.prevent="saveOfficial">
        <div>
          <label class="label" for="wa-phone">Numéro WhatsApp</label>
          <input id="wa-phone" v-model="official.display_phone" class="input" inputmode="tel" placeholder="+225 07 00 00 00 00">
        </div>
        <div>
          <label class="label" for="wa-pnid">Identifiant du numéro (Phone number ID)</label>
          <input id="wa-pnid" v-model="official.phone_number_id" class="input font-mono" inputmode="numeric" required>
        </div>
        <div>
          <label class="label" for="wa-waba">Identifiant du compte WhatsApp Business (WABA ID)</label>
          <input id="wa-waba" v-model="official.waba_id" class="input font-mono" inputmode="numeric" required>
        </div>
        <div>
          <label class="label" for="wa-token">Jeton d'accès permanent</label>
          <input id="wa-token" v-model="official.access_token" class="input font-mono" type="password" autocomplete="off" :required="!state.official?.has_token" :placeholder="state.official?.has_token ? 'Jeton enregistré : laisser vide pour le garder' : 'Jeton d\'un utilisateur système'">
        </div>
        <div class="md:col-span-2 flex flex-wrap gap-2">
          <button class="btn-primary" :disabled="busy">Enregistrer et vérifier</button>
          <button v-if="state.official" type="button" class="btn-secondary" :disabled="busy" @click="verifyOfficial">Vérifier à nouveau</button>
          <button v-if="state.official" type="button" class="btn-secondary text-red-700" :disabled="busy" @click="removeOfficial">Retirer</button>
        </div>
      </form>
      <details class="text-xs text-gray-600">
        <summary class="cursor-pointer font-medium">Où trouver ces informations ?</summary>
        <ol class="mt-2 list-decimal space-y-1 pl-5">
          <li>Meta Business Suite › WhatsApp Manager : l'identifiant du numéro et celui du compte WhatsApp Business.</li>
          <li>Paramètres de l'entreprise › Utilisateurs système : créez un jeton sans expiration avec les autorisations whatsapp_business_management et whatsapp_business_messaging.</li>
          <li>Dans l'application Meta, webhook WhatsApp : <code class="break-all">{{ state.webhook_url }}</code> (champ « messages »).</li>
        </ol>
      </details>
    </section>

    <!-- Connexion du numéro de Jibiat par QR code (secours de la connexion officielle) -->
    <section v-if="state && state.qr_state.status !== 'connected'" class="card p-4 space-y-3">
      <h2 class="font-semibold">Connexion par QR code<span v-if="state.channel === 'official'" class="font-normal text-gray-500"> (secours)</span></h2>
      <p class="text-sm text-gray-600">
        Reliez le numéro WhatsApp de Jibiat comme WhatsApp Web : les messages des entreprises et des prospects arrivent ici,
        et vous leur répondez depuis la console, avec ce numéro.
      </p>
      <p class="rounded-lg bg-amber-50 p-3 text-xs text-amber-900">
        Connexion non officielle : gardez des échanges normaux (pas d'envois en masse), et le téléphone allumé avec Internet.
      </p>
      <div v-if="state.qr" class="flex flex-wrap items-start gap-4">
        <img :src="state.qr" alt="QR code WhatsApp à scanner" class="h-64 w-64 rounded-lg border bg-white p-2">
        <ol class="list-decimal space-y-1 pl-5 text-sm text-gray-700">
          <li>Sur le téléphone du numéro de Jibiat, ouvrez WhatsApp.</li>
          <li>Touchez ⋮ (ou Réglages), puis « Appareils connectés ».</li>
          <li>Touchez « Connecter un appareil » et scannez ce QR code.</li>
        </ol>
      </div>
      <p v-if="state.qr_state.error" class="text-sm text-amber-700">{{ state.qr_state.error }}</p>
      <button v-if="!state.qr" type="button" class="btn-success" :disabled="busy || !state.available" @click="connect">
        {{ state.qr_state.status === 'disconnected' ? 'Reconnecter par QR code' : 'Afficher le QR code' }}
      </button>
      <p v-if="!state.available" class="text-xs text-gray-500">Le service WhatsApp n'est pas encore installé sur le serveur.</p>
    </section>

    <p v-else-if="state" class="text-sm text-gray-600">
      ✅ Numéro <strong>{{ state.qr_state.phone }}</strong><span v-if="state.qr_state.name"> ({{ state.qr_state.name }})</span> relié par QR code<span v-if="state.channel === 'official'"> (secours)</span>.
      <button type="button" class="ml-2 text-red-700 underline" :disabled="busy" @click="disconnect">Déconnecter</button>
    </p>

    <!-- SMS : coût à surveiller, secours à n'activer qu'en connaissance de cause -->
    <section v-if="state?.sms" class="card flex flex-wrap items-center justify-between gap-3 p-4 text-sm">
      <div>
        <p class="font-semibold">SMS envoyés (payants)</p>
        <p class="text-gray-600">
          Ce mois : <strong>{{ state.sms.month }}</strong> · mois dernier : {{ state.sms.last_month }}.
          <span v-if="!state.sms.ready" class="text-amber-700">Les SMS sont simulés sur ce serveur (aucun envoi réel).</span>
          <span v-else-if="state.status === 'connected'">Avec le numéro relié, les inscriptions et connexions passent par WhatsApp : aucun SMS, sauf si le secours est activé.</span>
          <span v-else class="text-amber-700">Sans numéro WhatsApp relié, chaque vérification de numéro part par SMS.</span>
        </p>
      </div>
      <label class="flex items-center gap-2">
        <input type="checkbox" class="h-4 w-4 rounded border-gray-300" :checked="state.sms.fallback" :disabled="busy" @change="setSmsFallback($event.target.checked)">
        <span>Proposer aussi le SMS quand WhatsApp est possible</span>
      </label>
    </section>

    <!-- Messagerie -->
    <div class="grid gap-4 md:grid-cols-[18rem_1fr]">
      <aside class="card flex max-h-[70vh] flex-col overflow-hidden">
        <div class="space-y-2 border-b p-2">
          <input v-model="search" class="input" placeholder="Rechercher (nom, numéro, entreprise)" @input="loadConversations">
          <form class="flex gap-1" @submit.prevent="openNumber">
            <input v-model="newPhone" class="input" placeholder="Écrire à un numéro" inputmode="tel">
            <button class="btn-secondary px-2" :disabled="!newPhone.trim()">＋</button>
          </form>
        </div>
        <ul class="flex-1 divide-y overflow-y-auto">
          <li v-for="c in conversations" :key="c.id">
            <button type="button" :class="['w-full px-3 py-2 text-left hover:bg-slate-50', currentId === c.id ? 'bg-slate-100' : '']" @click="openConversation(c.id)">
              <span class="flex items-center justify-between gap-2">
                <span class="truncate font-medium">{{ c.company?.name || c.name || c.phone }}</span>
                <span v-if="c.unread" class="rounded-full bg-emerald-600 px-1.5 text-xs text-white">{{ c.unread }}</span>
              </span>
              <span class="block truncate text-xs text-gray-500">{{ c.last_message || c.phone }}</span>
            </button>
          </li>
          <li v-if="!conversations.length" class="p-3 text-sm text-gray-500">Aucune conversation pour l'instant.</li>
        </ul>
      </aside>

      <section class="card flex max-h-[70vh] flex-col overflow-hidden">
        <template v-if="thread">
          <header class="border-b p-3">
            <p class="font-medium">{{ thread.conversation.company?.name || thread.conversation.name || thread.conversation.phone }}</p>
            <p class="text-xs text-gray-500">
              {{ thread.conversation.phone }}
              <RouterLink v-if="thread.conversation.company" :to="`/console/entreprises/${thread.conversation.company.id}`" class="ml-2 underline">fiche de l'entreprise</RouterLink>
              <a :href="`https://wa.me/${thread.conversation.phone.replace(/\D/g, '')}`" target="_blank" rel="noopener" class="ml-2 underline">ouvrir dans WhatsApp</a>
            </p>
          </header>
          <div ref="scroller" class="flex-1 space-y-2 overflow-y-auto bg-slate-50 p-3">
            <div v-for="m in thread.messages" :key="m.id" :class="['flex', m.direction === 'out' ? 'justify-end' : 'justify-start']">
              <div :class="['max-w-[80%] whitespace-pre-wrap rounded-lg px-3 py-2 text-sm shadow-sm', m.direction === 'out' ? 'bg-emerald-100' : 'bg-white']">
                {{ m.body }}
                <span class="mt-1 block text-right text-[11px] text-gray-500">
                  {{ m.by ? m.by + ' · ' : '' }}{{ dateTime(m.at) }}{{ m.direction === 'out' ? ' · ' + (SENT[m.status] || m.status) : '' }}
                </span>
                <span v-if="m.error" class="block text-[11px] text-red-700">{{ m.error }}</span>
              </div>
            </div>
          </div>
          <form class="flex gap-2 border-t p-2" @submit.prevent="send">
            <textarea v-model="reply" class="input min-h-[2.75rem]" rows="2" maxlength="4000" placeholder="Votre réponse…" @keydown.enter.exact.prevent="reply.trim() && !sending && send()" />
            <button class="btn-primary" :disabled="sending || !reply.trim() || state?.status !== 'connected'">Envoyer</button>
          </form>
        </template>
        <p v-else class="p-6 text-sm text-gray-500">Choisissez une conversation.</p>
      </section>
    </div>
  </div>
</template>

<script setup>
import { nextTick, onMounted, onUnmounted, reactive, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { useToastStore } from '../../stores/toasts'
import { dateTime } from '../../utils/format'

/**
 * Console › WhatsApp : numéro de Jibiat (connexion officielle de Meta saisie à la main, ou QR code), conversations avec les entreprises et les prospects,
 * réponses depuis la console. Tout se rafraîchit toutes les cinq secondes.
 */
const toasts = useToastStore()
const state = ref(null)
const conversations = ref([])
const thread = ref(null)
const currentId = ref(null)
const search = ref('')
const newPhone = ref('')
const reply = ref('')
const busy = ref(false)
const sending = ref(false)
const scroller = ref(null)
let timer = null

const STATUS = {
  none: ['Non relié', 'bg-slate-100 text-slate-700'],
  pending: ['QR code à scanner', 'bg-amber-100 text-amber-800'],
  connected: ['Connecté', 'bg-emerald-100 text-emerald-800'],
  disconnected: ['Déconnecté', 'bg-red-100 text-red-800'],
}
const OFFICIAL = {
  pending: ['À vérifier', 'bg-amber-100 text-amber-800'],
  connected: ['Vérifiée par Meta', 'bg-emerald-100 text-emerald-800'],
  error: ['Erreur', 'bg-red-100 text-red-800'],
}
const QUALITY = { green: 'bonne', yellow: 'moyenne', red: 'faible' }
const official = reactive({ display_phone: '', phone_number_id: '', waba_id: '', access_token: '' })
let officialFilled = false
const SENT = { queued: 'en attente', sent: 'envoyé', delivered: 'reçu', read: 'lu', failed: 'échec' }

async function loadState() {
  state.value = (await http.get('/console/whatsapp')).data.data
  // Formulaire rempli une seule fois (pas à chaque rafraîchissement, pour ne pas effacer la saisie)
  if (!officialFilled) {
    const o = state.value.official
    Object.assign(official, { display_phone: o?.display_phone || '', phone_number_id: o?.phone_number_id || '', waba_id: o?.waba_id || '', access_token: '' })
    officialFilled = true
  }
}

async function officialCall(request, done) {
  busy.value = true
  try {
    state.value = (await request()).data.data
    official.access_token = ''
    const o = state.value.official
    if (o?.live) toasts.success(done)
    else if (o?.error) toasts.error(o.error)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

const saveOfficial = () => officialCall(() => http.put('/console/whatsapp/official', { ...official, access_token: official.access_token || null }), 'Connexion officielle vérifiée.')
const verifyOfficial = () => officialCall(() => http.post('/console/whatsapp/official/verify'), 'Connexion officielle vérifiée.')

async function removeOfficial() {
  if (!window.confirm('Retirer la connexion officielle ? La console repassera par le QR code s\'il est relié.')) return
  await officialCall(() => http.delete('/console/whatsapp/official'), '')
  Object.assign(official, { display_phone: '', phone_number_id: '', waba_id: '', access_token: '' })
}

async function loadConversations() {
  conversations.value = (await http.get('/console/whatsapp/conversations', { params: { q: search.value || undefined } })).data.data
}

async function openConversation(id, scroll = true) {
  currentId.value = id
  const count = thread.value?.messages.length
  thread.value = (await http.get(`/console/whatsapp/conversations/${id}`)).data.data
  const c = conversations.value.find((x) => x.id === id)
  if (c) c.unread = 0
  if (scroll || thread.value.messages.length !== count) {
    await nextTick()
    if (scroller.value) scroller.value.scrollTop = scroller.value.scrollHeight
  }
}

async function openNumber() {
  try {
    const { data } = await http.post('/console/whatsapp/conversations', { phone: newPhone.value })
    newPhone.value = ''
    await loadConversations()
    await openConversation(data.data.id)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function send() {
  sending.value = true
  try {
    const { data } = await http.post(`/console/whatsapp/conversations/${currentId.value}/messages`, { body: reply.value })
    if (data.data.status === 'failed') toasts.error(data.data.error || 'Message non envoyé.')
    reply.value = ''
    await openConversation(currentId.value)
    await loadConversations()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    sending.value = false
  }
}

async function connect() {
  busy.value = true
  try {
    state.value = (await http.post('/console/whatsapp/connect')).data.data
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

async function disconnect() {
  if (!window.confirm('Déconnecter le numéro de Jibiat ? Les messages n\'arriveront plus dans la console.')) return
  busy.value = true
  try {
    state.value = (await http.delete('/console/whatsapp/connect')).data.data
  } finally {
    busy.value = false
  }
}

async function setSmsFallback(on) {
  if (on && !window.confirm('Chaque SMS coûte. Proposer le SMS même quand WhatsApp est possible ?')) {
    await loadState()
    return
  }
  busy.value = true
  try {
    state.value = (await http.put('/console/whatsapp/sms', { fallback: on })).data.data
    toasts.success(on ? 'SMS proposé en secours.' : 'SMS réservé aux numéros sans WhatsApp.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
    await loadState()
  } finally {
    busy.value = false
  }
}

async function tick() {
  try {
    await Promise.all([loadState(), loadConversations()])
    if (currentId.value) await openConversation(currentId.value, false)
  } catch {
    // réseau passager : nouvel essai au prochain tour
  }
}

onMounted(async () => {
  await tick()
  timer = setInterval(tick, 5000)
})
onUnmounted(() => clearInterval(timer))
</script>
