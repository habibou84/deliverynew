<template>
  <div class="max-w-2xl space-y-4">
    <h1 class="text-xl font-bold">Paramètres de l'entreprise</h1>

    <!-- Adresse de l'espace et domaine personnalisé (plateforme) -->
    <CustomDomainSettings />

    <!-- Identité visuelle : page d'accueil des e-commerçants, pages de connexion, en-tête -->
    <section v-if="form" class="card p-4 space-y-3">
      <h2 class="font-semibold">Logo et page d'accueil</h2>
      <div class="flex flex-wrap items-center gap-4">
        <div class="h-20 w-40 rounded-lg border border-dashed border-slate-300 grid place-items-center bg-slate-50 overflow-hidden">
          <img v-if="logoUrl" :src="logoUrl" alt="Logo actuel" class="max-h-20 max-w-40 object-contain">
          <span v-else class="text-xs text-gray-500">Aucun logo</span>
        </div>
        <div class="space-y-2">
          <label class="btn-secondary cursor-pointer inline-block">
            {{ logoUrl ? 'Changer le logo' : 'Envoyer un logo' }}
            <input type="file" accept="image/png,image/jpeg,image/webp" class="sr-only" :disabled="uploading" @change="uploadLogo">
          </label>
          <button v-if="logoUrl" type="button" class="btn-secondary text-red-700 ml-2" @click="removeLogo">Retirer</button>
          <p class="text-xs text-gray-500">PNG, JPEG ou WebP, 2 Mo au plus. Fond transparent conseillé (PNG).</p>
        </div>
      </div>
      <p class="text-xs text-gray-500">Affiché sur la page d'accueil des e-commerçants (adresse du site), les pages de connexion, l'en-tête de chaque espace et l'icône des applications.</p>
    </section>

    <!-- Couleurs des espaces, noms et icônes des applications -->
    <AppearanceSettings v-if="form" />

    <form v-if="form" class="card p-4 space-y-4" @submit.prevent="save">
      <div class="grid sm:grid-cols-2 gap-3">
        <div><label class="label" for="name">Nom</label><input id="name" v-model="form.name" class="input" required></div>
        <div><label class="label" for="phone">Téléphone</label><input id="phone" v-model="form.phone" class="input"></div>
        <div class="sm:col-span-2"><label class="label" for="address">Adresse</label><input id="address" v-model="form.address" class="input"></div>
        <div class="sm:col-span-2">
          <label class="label" for="tagline">Accroche de la page d'accueil des e-commerçants</label>
          <input id="tagline" v-model="form.tagline" class="input" maxlength="160" placeholder="Vos colis livrés vite, vos clients satisfaits.">
        </div>
      </div>

      <fieldset class="space-y-3 border-t pt-4">
        <legend class="font-semibold">E-commerçants</legend>
        <label class="flex items-start gap-2 text-sm">
          <input v-model="form.merchant_signup" type="checkbox" class="mt-1">
          <span>Inscriptions en ligne ouvertes<span class="block text-gray-500">Les e-commerçants créent leur compte depuis la page d'accueil (« Créer mon compte »). Le compte est actif dès que le numéro est vérifié par WhatsApp ou SMS ; vous êtes prévenu à chaque inscription.</span></span>
        </label>
      </fieldset>

      <fieldset class="space-y-3 border-t pt-4">
        <legend class="font-semibold">Point de la semaine</legend>
        <label class="flex items-start gap-2 text-sm">
          <input v-model="form.weekly_report" type="checkbox" class="mt-1">
          <span>Envoyer le point de la semaine aux administrateurs<span class="block text-gray-500">Chaque lundi vers 7 h 30, par WhatsApp et dans la cloche : courses, taux de livraison, chiffre d'affaires comparés à la semaine d'avant, e-commerçants à relancer.</span></span>
        </label>
      </fieldset>

      <fieldset class="space-y-3 border-t pt-4">
        <legend class="font-semibold">Courses</legend>
        <div class="space-y-2" role="radiogroup" aria-label="Validation des nouvelles courses">
          <p class="text-sm font-medium">Validation des nouvelles courses</p>
          <label v-for="m in VALIDATION_MODES" :key="m.value" :class="['flex items-start gap-2 rounded-lg border p-3 text-sm cursor-pointer', form.validation_mode === m.value ? 'border-blue-500 bg-blue-50' : 'border-slate-200']">
            <input v-model="form.validation_mode" type="radio" :value="m.value" class="mt-1">
            <span><strong>{{ m.label }}</strong><span class="block text-gray-500">{{ m.help }}</span></span>
          </label>
          <p v-if="form.validation_mode === 'rules' && !form.daily_cutoff_time" class="text-xs text-amber-700">
            Choisissez l'heure limite du jour plus bas : sans elle, seule la fermeture de la journée par le dispatch décale les courses.
          </p>
        </div>
        <label class="flex items-start gap-2 text-sm">
          <input v-model="form.require_delivery_code" type="checkbox" class="mt-1">
          <span>Exiger le code de livraison du destinataire<span class="block text-gray-500">Le destinataire reçoit son code sur WhatsApp quand le livreur part livrer (Paramètres WhatsApp), ou par le marchand.</span></span>
        </label>
        <label class="flex items-start gap-2 text-sm">
          <input v-model="form.offline_courier_actions" type="checkbox" class="mt-1">
          <span>Mode hors connexion des livreurs
            <span class="block text-gray-500">
              Sans réseau, le livreur peut quand même enregistrer « récupéré », « en route », « livré » (avec l'encaissement), un échec, un report ou une note :
              l'application les envoie seule au retour du réseau, à l'heure où ils ont été faits. Une livraison avec un code client faux est gardée
              mais signalée (file « Codes à vérifier »). Les WhatsApp au client et au marchand partent au retour du réseau.
            </span>
          </span>
        </label>
        <div>
          <label class="label" for="attempts">Nombre maximal de tentatives de livraison</label>
          <input id="attempts" v-model.number="form.default_max_attempts" type="number" min="1" max="10" class="input w-24">
        </div>
        <div>
          <label class="label" for="reminder">Relancer un problème signalé par un livreur et resté sans suite après (minutes)</label>
          <input id="reminder" v-model.number="form.field_alert_reminder_minutes" type="number" min="0" max="240" class="input w-24">
          <p class="text-xs text-gray-500 mt-1">Relance au dispatch après ce délai, puis aux administrateurs après trois fois ce délai. 0 = pas de relance. Répondre au livreur ou traiter la remontée arrête les relances.</p>
        </div>
        <div>
          <label class="label" for="hold-alert">Alerter quand un colis non livré reste chez un livreur depuis plus de (heures)</label>
          <input id="hold-alert" v-model.number="form.parcel_hold_alert_hours" type="number" min="0" max="168" class="input w-24">
          <p class="text-xs text-gray-500 mt-1">Alerte sonore au dispatch, une fois par colis, et colis signalé en rouge dans « Colis chez les livreurs ». 0 = jamais.</p>
        </div>
        <!-- Après une mission ramassage (écran « Livreurs disponibles ») -->
        <div>
          <p class="label">Après une mission ramassage, les colis…</p>
          <div class="grid sm:grid-cols-3 gap-2">
            <label v-for="m in CUSTODY_MODES" :key="m.value" :class="['rounded-lg border p-3 text-sm cursor-pointer', form.pickup_mission_custody === m.value ? 'border-slate-900 ring-1 ring-slate-900' : 'border-slate-200']">
              <input v-model="form.pickup_mission_custody" type="radio" :value="m.value" class="mr-1">
              <strong>{{ m.label }}</strong>
              <span class="block text-xs text-gray-500 mt-1">{{ m.help }}</span>
            </label>
          </div>
        </div>
        <div v-if="form.pickup_mission_custody !== 'keep'">
          <label class="label" for="depot-closes">Heure de fermeture du dépôt</label>
          <input id="depot-closes" v-model="form.depot_closes_at" type="time" class="input w-32">
          <p class="text-xs text-gray-500 mt-1">Passé cette heure, le livreur garde les colis d'une mission ramassage jusqu'à leur livraison. Vide = dépôt toujours ouvert.</p>
        </div>
        <div class="grid sm:grid-cols-2 gap-3">
          <div>
            <label class="label" for="pickup-alert">Alerter quand une course validée reste sans livreur de ramassage plus de (minutes)</label>
            <input id="pickup-alert" v-model.number="form.pickup_assign_alert_minutes" type="number" min="0" max="1440" class="input w-24">
          </div>
          <div>
            <label class="label" for="delivery-alert">Alerter quand un colis récupéré reste sans livreur de livraison plus de (minutes)</label>
            <input id="delivery-alert" v-model.number="form.delivery_assign_alert_minutes" type="number" min="0" max="1440" class="input w-24">
          </div>
          <p class="sm:col-span-2 text-xs text-gray-500 -mt-1">
            Au-delà, la course passe en rouge dans la liste, le compteur du menu « Courses » augmente et le dispatch reçoit une alerte sonore
            (une fois par course) ; les administrateurs sont prévenus après trois fois ce délai. Une livraison prévue un autre jour ne compte
            qu'à partir de ce jour. 0 = pas d'alerte.
          </p>
          <div class="sm:col-span-2">
            <label class="label" for="cutoff">Heure limite du jour</label>
            <input id="cutoff" v-model="form.daily_cutoff_time" type="time" class="input w-32">
            <p class="text-xs text-gray-500 mt-1">
              Passé cette heure, les courses du jour encore sans livreur (ramassage ou livraison) risquent de ne pas être livrées
              aujourd'hui : elles sont signalées dans la liste et le tableau de bord, et les administrateurs et le dispatch reçoivent
              une alerte (une fois par jour). En validation avec règles, les nouvelles courses du jour arrivées après cette heure
              sont validées pour le lendemain. Vide = pas d'heure limite.
            </p>
          </div>
        </div>
      </fieldset>

      <fieldset class="space-y-3 border-t pt-4">
        <legend class="font-semibold">Finance</legend>
        <div>
          <label class="label" for="returnfee">Frais facturés au marchand pour un colis retourné (% des frais de livraison)</label>
          <input id="returnfee" v-model.number="form.return_fee_percent" type="number" min="0" max="100" class="input w-24">
        </div>
      </fieldset>

      <p v-if="error" class="field-error">{{ error }}</p>
      <button class="btn-primary" :disabled="saving">Enregistrer</button>
    </form>

    <!-- Reprise des comptes de l'ancienne application -->
    <LegacyImportSettings v-if="form" />
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import CustomDomainSettings from '../../components/CustomDomainSettings.vue'
import AppearanceSettings from '../../components/AppearanceSettings.vue'
import LegacyImportSettings from '../../components/LegacyImportSettings.vue'
import { useAuthStore } from '../../stores/auth'
import { useToastStore } from '../../stores/toasts'
import { loadBranding } from '../../composables/useBranding'

const FIELDS = ['name', 'phone', 'address', 'tagline', 'merchant_signup', 'weekly_report', 'validation_mode', 'require_delivery_code', 'offline_courier_actions', 'default_max_attempts', 'return_fee_percent', 'field_alert_reminder_minutes', 'parcel_hold_alert_hours', 'pickup_assign_alert_minutes', 'delivery_assign_alert_minutes', 'daily_cutoff_time', 'pickup_mission_custody', 'depot_closes_at']

const CUSTODY_MODES = [
  { value: 'choose', label: 'Au choix du dispatch', help: 'Le dispatch choisit à chaque mission : garde ou dépôt.' },
  { value: 'keep', label: 'Restent chez le livreur', help: 'Il les garde jusqu\'à leur jour de livraison, qui lui est alors confiée automatiquement.' },
  { value: 'depot', label: 'Reviennent au dépôt', help: 'Il les rapporte au dépôt le soir même ; n\'importe quel livreur pourra les livrer.' },
]

const VALIDATION_MODES = [
  { value: 'manual', label: 'Manuelle', help: 'Chaque course attend la validation d\'un dispatcher (file « À valider »).' },
  { value: 'auto', label: 'Automatique', help: 'Toutes les courses sont validées dès leur enregistrement.' },
  {
    value: 'rules',
    label: 'Automatique avec règles',
    help: 'Validées dès leur enregistrement ; après l\'heure limite du jour, ou quand le dispatch ferme la journée, les courses du jour sont validées pour le lendemain (ramassage le jour même) et le marchand est prévenu.',
  },
]

const auth = useAuthStore()
const toasts = useToastStore()
const form = ref(null)
const error = ref('')
const saving = ref(false)
const logoUrl = ref(null)
const uploading = ref(false)

async function uploadLogo(event) {
  const file = event.target.files[0]
  event.target.value = ''
  if (!file) return
  uploading.value = true
  try {
    const body = new FormData()
    body.append('logo', file)
    logoUrl.value = (await http.post('/company/logo', body)).data.data.logo_url
    loadBranding(true)
    toasts.success('Logo enregistré.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    uploading.value = false
  }
}

async function removeLogo() {
  await http.delete('/company/logo')
  logoUrl.value = null
  loadBranding(true)
}

onMounted(async () => {
  const { data } = await http.get(`/companies/${auth.user.company_id}`)
  form.value = Object.fromEntries(FIELDS.map((f) => [f, data.data[f]]))
  logoUrl.value = data.data.logo_url
})

async function save() {
  saving.value = true
  error.value = ''
  try {
    // L'accroche, l'heure limite et l'heure de fermeture du dépôt peuvent être vidées ; les autres champs vides sont ignorés
    const clearable = ['tagline', 'daily_cutoff_time', 'depot_closes_at']
    const payload = Object.fromEntries(Object.entries(form.value)
      .filter(([k, v]) => clearable.includes(k) || (v !== null && v !== ''))
      .map(([k, v]) => [k, ['daily_cutoff_time', 'depot_closes_at'].includes(k) ? v || null : v]))
    await http.patch(`/companies/${auth.user.company_id}`, payload)
    await auth.fetchUser()
    toasts.success('Paramètres enregistrés.')
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    saving.value = false
  }
}
</script>
