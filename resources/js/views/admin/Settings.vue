<template>
  <div class="max-w-2xl space-y-4">
    <h1 class="text-xl font-bold">Paramètres de l'entreprise</h1>

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
      <p class="text-xs text-gray-500">Affiché sur la page d'accueil des e-commerçants (adresse du site), les pages de connexion et l'en-tête du back-office.</p>
    </section>

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
        <legend class="font-semibold">Courses</legend>
        <label class="flex items-start gap-2 text-sm">
          <input v-model="form.auto_confirm_orders" type="checkbox" class="mt-1">
          <span>Valider automatiquement les nouvelles courses<span class="block text-gray-500">Sinon, chaque course attend la validation d'un dispatcher.</span></span>
        </label>
        <label class="flex items-start gap-2 text-sm">
          <input v-model="form.require_delivery_code" type="checkbox" class="mt-1">
          <span>Exiger le code de livraison du destinataire<span class="block text-gray-500">Le destinataire reçoit son code sur WhatsApp quand le livreur part livrer (Paramètres WhatsApp), ou par le marchand.</span></span>
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
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { useAuthStore } from '../../stores/auth'
import { useToastStore } from '../../stores/toasts'
import { loadBranding } from '../../composables/useBranding'

const FIELDS = ['name', 'phone', 'address', 'tagline', 'auto_confirm_orders', 'require_delivery_code', 'default_max_attempts', 'return_fee_percent', 'field_alert_reminder_minutes', 'parcel_hold_alert_hours']

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
    // L'accroche peut être vidée ; les autres champs vides sont ignorés
    const payload = Object.fromEntries(Object.entries(form.value).filter(([k, v]) => k === 'tagline' || (v !== null && v !== '')))
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
