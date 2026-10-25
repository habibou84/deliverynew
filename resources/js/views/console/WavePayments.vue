<template>
  <div class="space-y-4">
    <h1 class="text-xl font-bold">Paiements Wave</h1>

    <div class="card p-4 text-sm">
      <h2 class="mb-1 font-semibold">Paiements à valider</h2>
      <p class="mb-3 text-gray-600">Vérifiez dans Wave que le montant est arrivé depuis ce numéro, puis confirmez : le paiement est enregistré sur la facture. Vous êtes connecté : pas besoin du code.</p>
      <p v-if="loaded && !pending.length" class="text-gray-500">Aucun paiement en attente.</p>
      <ul class="divide-y">
        <li v-for="p in pending" :key="p.id" class="flex flex-wrap items-center gap-3 py-3">
          <div class="min-w-0 flex-1">
            <p class="font-medium">
              {{ money(p.amount) }} · {{ p.payer_phone }}
              <span v-if="p.claimed_at" class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-xs text-amber-900">Déclaré payé {{ dateTime(p.claimed_at) }}</span>
            </p>
            <p class="text-gray-600">
              <RouterLink :to="`/console/entreprises/${p.company.id}`" class="text-blue-600 hover:underline">{{ p.company.name }}</RouterLink>
              <template v-if="p.payer_name"> · {{ p.payer_name }}</template> ·
              <RouterLink :to="`/console/factures/${p.invoice.id}`" class="text-blue-600 hover:underline">{{ p.invoice.number }}</RouterLink> ({{ p.invoice.period_label }})
            </p>
            <p class="text-xs text-gray-500">Annoncé {{ dateTime(p.created_at) }}<template v-if="p.alerts.length"> · alerte {{ p.alerts.map((a) => CHANNELS[a.channel] ?? 'non envoyée').join(', ') }}</template></p>
          </div>
          <button class="btn-primary" :disabled="busy === p.id" @click="decide(p, 'received')">Reçu : enregistrer</button>
          <button class="btn-secondary" :disabled="busy === p.id" @click="decide(p, 'missing')">Non reçu</button>
        </li>
      </ul>
    </div>

    <form v-if="settings" class="card space-y-3 p-4 text-sm" @submit.prevent="save">
      <h2 class="font-semibold">Lien marchand Wave</h2>
      <p class="text-gray-600">Avec un lien ou un QR code, les entreprises voient « Payer avec Wave » sur leurs factures (Mon abonnement).</p>
      <div>
        <label class="label" for="w-link">Lien de paiement marchand Wave</label>
        <input id="w-link" v-model.trim="form.link" class="input" placeholder="https://pay.wave.com/m/…">
        <p class="mt-1 text-xs text-gray-500">Le montant est ajouté au lien. Mettez {montant} dans le lien pour choisir sa place.</p>
      </div>
      <div class="space-y-2">
        <p class="label">QR code Wave</p>
        <p class="text-xs text-gray-500">Affiché aux entreprises sur ordinateur : elles le scannent avec Wave sur leur téléphone.</p>
        <div class="flex flex-wrap items-center gap-4">
          <img v-if="settings.qr_url" :src="settings.qr_url" alt="QR code Wave" class="h-32 w-32 rounded object-contain ring-1 ring-slate-200">
          <label class="btn-secondary cursor-pointer">{{ settings.qr_url ? 'Remplacer' : 'Ajouter le QR code' }}<input type="file" accept="image/png,image/jpeg,image/webp" class="hidden" @change="uploadQr"></label>
          <button v-if="settings.qr_url" type="button" class="text-red-600 underline" @click="removeQr">Retirer</button>
        </div>
      </div>
      <div class="grid gap-3 sm:grid-cols-2">
        <div><label class="label" for="w-email">E-mail du validateur</label><input id="w-email" v-model.trim="form.validator_email" type="email" class="input" placeholder="paiements@…"></div>
        <div><label class="label" for="w-phone">WhatsApp du validateur</label><input id="w-phone" v-model.trim="form.validator_phone" type="tel" class="input" placeholder="07 00 00 00 00"></div>
      </div>
      <div class="max-w-xs">
        <label class="label" for="w-pin">Code de validation</label>
        <input id="w-pin" v-model="form.validator_pin" type="password" inputmode="numeric" autocomplete="new-password" class="input" :placeholder="settings.pin_set ? 'Déjà défini : laisser vide pour le garder' : 'Au moins 6 chiffres'">
        <p class="mt-1 text-xs text-gray-500">Demandé quand vous validez depuis le lien reçu par WhatsApp, e-mail ou SMS.</p>
      </div>
      <div class="max-w-xs">
        <label class="label" for="w-current">Votre mot de passe</label>
        <input id="w-current" v-model="form.current_password" type="password" autocomplete="current-password" class="input" required>
        <p class="mt-1 text-xs text-gray-500">Demandé à chaque changement : ces réglages décident où arrive l'argent.</p>
      </div>
      <button class="btn-primary">Enregistrer</button>
    </form>

    <div v-if="settings" class="card space-y-3 p-4 text-sm">
      <h2 class="font-semibold">Envoi des alertes</h2>
      <p class="text-gray-600">
        À chaque paiement, le validateur reçoit un message <b>WhatsApp depuis le numéro de Jibiat</b>, avec le lien pour confirmer.
        S'il ne part pas : un e-mail, puis un SMS. Le paiement apparaît aussi dans la cloche et sur cette page.
      </p>
      <ul class="space-y-1">
        <li>
          <span :class="settings.whatsapp_ready ? 'text-emerald-700' : 'text-amber-700'">{{ settings.whatsapp_ready ? '✓' : '✗' }} WhatsApp :</span>
          {{ settings.whatsapp_ready ? 'le numéro de Jibiat est relié' : 'le numéro de Jibiat n\'est pas relié' }}
          <RouterLink v-if="!settings.whatsapp_ready" to="/console/whatsapp" class="text-blue-600 underline">Relier le numéro</RouterLink>
        </li>
        <li>
          <span :class="settings.mail.source ? 'text-emerald-700' : 'text-amber-700'">{{ settings.mail.source ? '✓' : '✗' }} E-mail :</span>
          {{ settings.mail.source === 'gmail' ? `par Gmail (${settings.mail.gmail.email})` : settings.mail.source === 'console' ? `par ${settings.mail.host} (réglé ci-dessous)` : settings.mail.source === 'server' ? 'par le serveur' : 'facultatif, non réglé' }}
        </li>
        <li>
          <span :class="settings.sms_ready ? 'text-emerald-700' : 'text-amber-700'">{{ settings.sms_ready ? '✓' : '✗' }} SMS :</span>
          {{ settings.sms_ready ? 'le serveur envoie les SMS' : 'pas de fournisseur de SMS sur le serveur : les SMS ne partent pas' }}
        </li>
      </ul>
      <p v-if="settings.whatsapp_ready && !settings.mail.source" class="rounded-lg bg-emerald-50 p-2 text-xs text-emerald-800">
        WhatsApp suffit : l'e-mail n'est qu'un secours, vous pouvez le laisser vide.
      </p>
      <div class="space-y-2 rounded-lg bg-slate-50 p-3">
        <p class="font-medium">E-mail par Gmail, sans mot de passe</p>
        <template v-if="settings.mail.gmail.connected">
          <p class="text-emerald-700">✓ Gmail relié : les e-mails partent de <b>{{ settings.mail.gmail.email }}</b>.</p>
          <p v-if="settings.mail.gmail.error" class="text-red-600">{{ settings.mail.gmail.error }}</p>
          <button type="button" class="btn-secondary" @click="disconnectGmail">Déconnecter Gmail</button>
        </template>
        <template v-else-if="settings.mail.gmail.available">
          <p class="text-xs text-gray-600">
            Vous choisissez votre compte Gmail et Google demande d'autoriser Jibiat à <b>envoyer</b> des e-mails (Jibiat ne peut pas lire votre boîte).
            Aucun mot de passe n'est enregistré. Si Google affiche « application non vérifiée », touchez « Paramètres avancés » puis « Accéder à Jibiat ».
          </p>
          <p v-if="settings.mail.gmail.error" class="text-red-600">{{ settings.mail.gmail.error }}</p>
          <button type="button" class="btn-primary" :disabled="gmailBusy" @click="connectGmail">{{ gmailBusy ? 'Ouverture…' : 'Connecter Gmail' }}</button>
        </template>
        <p v-else class="text-xs text-amber-800">
          Le projet Google n'est pas encore réglé sur le serveur (GOOGLE_MAIL_CLIENT_ID et GOOGLE_MAIL_CLIENT_SECRET) : suivez docs/GMAIL.md, une seule fois.
        </p>
      </div>
      <details class="rounded-lg bg-slate-50 p-3" :open="settings.mail.source === 'console'">
      <summary class="cursor-pointer font-medium">Autre serveur d'e-mail (SMTP, facultatif)</summary>
      <form class="mt-3 space-y-3" @submit.prevent="saveMail">
        <p class="text-xs text-gray-600">
          Seulement si vous n'utilisez pas Gmail ci-dessus : boîte mail de votre domaine ou service d'envoi (serveur, port, identifiant, mot de passe).
        </p>
        <div class="grid gap-3 sm:grid-cols-[1fr_6rem]">
          <div><label class="label" for="m-host">Serveur</label><input id="m-host" v-model.trim="mail.host" class="input" placeholder="smtp.gmail.com"></div>
          <div><label class="label" for="m-port">Port</label><input id="m-port" v-model.number="mail.port" type="number" class="input"></div>
        </div>
        <div class="grid gap-3 sm:grid-cols-2">
          <div><label class="label" for="m-user">Identifiant</label><input id="m-user" v-model.trim="mail.username" type="email" class="input" autocomplete="off" placeholder="vous@…"></div>
          <div><label class="label" for="m-pass">Clé SMTP ou mot de passe d'application</label><input id="m-pass" v-model="mail.password" type="password" class="input" autocomplete="new-password" :placeholder="settings.mail.password_set ? 'Déjà enregistré : laisser vide pour le garder' : 'Clé donnée par le service'"></div>
        </div>
        <button class="btn-primary">Enregistrer l'e-mail de secours</button>
      </form>
      </details>
      <button class="btn-secondary" :disabled="testing" @click="testAlert">{{ testing ? 'Envoi…' : 'Envoyer une alerte d\'essai' }}</button>
      <ul v-if="testResult" class="space-y-1">
        <li :class="testResult.whatsapp.ok ? 'text-emerald-700' : 'text-red-600'">{{ testResult.whatsapp.ok ? '✓' : '✗' }} WhatsApp : {{ testResult.whatsapp.detail }}</li>
        <li :class="testResult.mail.ok ? 'text-emerald-700' : 'text-red-600'">{{ testResult.mail.ok ? '✓' : '✗' }} E-mail : {{ testResult.mail.detail }}</li>
        <li :class="testResult.sms.ok ? 'text-emerald-700' : 'text-red-600'">{{ testResult.sms.ok ? '✓' : '✗' }} SMS : {{ testResult.sms.detail }}</li>
      </ul>
    </div>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { consoleCan } from '../../roles'
import { useAuthStore } from '../../stores/auth'
import { useToastStore } from '../../stores/toasts'
import { money } from '../../utils/format'

const toasts = useToastStore()
const auth = useAuthStore()
const can = (group) => consoleCan(auth.role, group)
const CHANNELS = { whatsapp: 'WhatsApp', mail: 'e-mail', sms: 'SMS', journal: 'SMS non envoyé (pas de fournisseur)' }
const pending = ref([])
const loaded = ref(false)
const busy = ref(null)
const settings = ref(null)
const form = reactive({ link: '', validator_email: '', validator_phone: '', validator_pin: '', current_password: '' })
const mail = reactive({ host: '', port: 587, username: '', password: '' })
const testing = ref(false)
const gmailBusy = ref(false)
const testResult = ref(null)

const dateTime = (iso) => new Date(iso).toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })

function fill(data) {
  settings.value = data
  Object.assign(form, { link: data.link ?? '', validator_email: data.validator_email ?? '', validator_phone: data.validator_phone ?? '', validator_pin: '', current_password: '' })
  Object.assign(mail, { host: data.mail.host ?? '', port: Number(data.mail.port ?? 587), username: data.mail.username ?? '', password: '' })
}

async function loadPending() {
  pending.value = (await http.get('/console/wave/payments')).data.data
  loaded.value = true
}

async function decide(p, decision) {
  const text = decision === 'received'
    ? `Confirmer la réception de ${money(p.amount)} depuis le ${p.payer_phone} ? Le paiement sera enregistré sur la facture ${p.invoice.number}.`
    : `Marquer le paiement de ${money(p.amount)} comme non reçu ?`
  if (!window.confirm(text)) return
  busy.value = p.id
  try {
    await http.post(`/console/wave/payments/${p.id}/decide`, { decision })
    toasts.success(decision === 'received' ? 'Paiement enregistré sur la facture.' : 'Paiement marqué non reçu.')
    await loadPending()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = null
  }
}

async function save() {
  if (form.link !== (settings.value?.link ?? '') && !window.confirm('Changer le lien de paiement Wave ? Toutes les entreprises paieront leurs factures sur ce nouveau lien.')) return
  try {
    fill((await http.put('/console/wave', form)).data.data)
    toasts.success('Réglages Wave enregistrés.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function connectGmail() {
  gmailBusy.value = true
  try {
    window.location.href = (await http.post('/console/gmail/connect')).data.data.url
  } catch (e) {
    toasts.error(apiErrorMessage(e))
    gmailBusy.value = false
  }
}

async function disconnectGmail() {
  if (!window.confirm('Déconnecter Gmail ? Les e-mails ne partiront plus par ce compte.')) return
  try {
    fill((await http.delete('/console/gmail')).data.data)
    toasts.success('Gmail déconnecté.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function saveMail() {
  try {
    fill((await http.put('/console/wave/mail', mail)).data.data)
    toasts.success('Envoi des e-mails enregistré.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function uploadQr(event) {
  const file = event.target.files?.[0]
  if (!file) return
  const data = new FormData()
  data.append('image', file)
  try {
    fill((await http.post('/console/wave/qr', data)).data.data)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    event.target.value = ''
  }
}

async function removeQr() {
  if (!window.confirm('Retirer le QR code Wave ?')) return
  fill((await http.delete('/console/wave/qr')).data.data)
}

async function testAlert() {
  testing.value = true
  testResult.value = null
  try {
    testResult.value = (await http.post('/console/wave/test-alert')).data.data
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    testing.value = false
  }
}

const route = useRoute()
const router = useRouter()

onMounted(async () => {
  // Retour de Google après « Connecter Gmail »
  const gmail = route.query.gmail
  if (gmail === 'ok') toasts.success('Gmail relié : les e-mails partiront de votre compte.')
  else if (gmail === 'refus') toasts.error('Autorisation refusée dans Google : Gmail n\'est pas relié.')
  else if (gmail === 'erreur') toasts.error(String(route.query.message || 'Gmail n\'a pas pu être relié.'))
  if (gmail) router.replace({ query: {} })

  // Réglages Wave et envoi des alertes : Propriétaire seulement (le Comptable valide les paiements)
  if (can('owner')) fill((await http.get('/console/wave')).data.data)
  await loadPending()
})
</script>
