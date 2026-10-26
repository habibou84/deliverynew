<template>
  <SettingsPage icon="🏪" title="E-commerçants" intro="Comment les e-commerçants rejoignent votre entreprise.">
    <template v-if="form">
      <MerchantSignupBlock :form="form" />
      <DirectoryBlock :form="form" />

      <section v-if="inviteUrl" class="card p-4 space-y-3">
        <h2 class="font-semibold">Parrainer vos e-commerçants sur Jibiat Marchand</h2>
        <p class="text-sm text-gray-600">
          Jibiat Marchand gère leurs commandes, leur stock et leur boutique en ligne, et vous envoie leurs courses toutes seules.
          Avec votre lien, ils ont <strong>un mois d'essai en plus</strong>, et vous êtes leur entreprise de livraison attitrée.
        </p>
        <p class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-900">
          💰 Vous recevez <strong>{{ ratePercent }} % de leur abonnement</strong> pendant {{ months }} mois à partir de leur premier paiement,
          même s'ils ajoutent une autre entreprise. Jibiat Marchand ne leur propose jamais d'autres entreprises de livraison.
        </p>
        <p v-if="!form.directory_listed" class="text-sm text-amber-700">Le parrainage (mois offert et part de l'abonnement) ne compte que si votre entreprise figure dans l'annuaire.</p>
        <div class="flex flex-wrap items-start gap-4">
          <img v-if="qr" :src="qr" alt="QR code du lien d'invitation" class="h-32 w-32 rounded-lg border border-gray-200 bg-white p-1">
          <div class="min-w-0 flex-1 space-y-2">
            <div class="flex flex-wrap items-center gap-2">
              <input :value="inviteUrl" class="input flex-1 min-w-0 text-sm" readonly aria-label="Lien d'invitation" @focus="$event.target.select()">
              <button type="button" class="btn-secondary" @click="copy">{{ copied ? 'Copié ✓' : 'Copier' }}</button>
              <a :href="whatsappUrl" target="_blank" rel="noopener" class="btn-primary">Envoyer par WhatsApp</a>
            </div>
            <p class="text-xs text-gray-500">Le QR code s'imprime ou se montre au ramassage : l'e-commerçant le scanne avec son téléphone.</p>
          </div>
        </div>

        <div v-if="referrals" class="space-y-3 border-t pt-3">
          <h3 class="font-semibold text-sm">Vos e-commerçants parrainés</h3>
          <div class="grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
            <div class="rounded-lg bg-gray-50 p-2"><span class="block text-xs text-gray-500">Parrainés</span><strong>{{ referrals.meta.referred }}</strong> <span class="text-xs text-gray-500">dont {{ referrals.meta.paying }} abonnés</span></div>
            <div class="rounded-lg bg-gray-50 p-2"><span class="block text-xs text-gray-500">Gagné ce mois</span><strong>{{ money(referrals.meta.earned_month) }}</strong></div>
            <div class="rounded-lg bg-gray-50 p-2"><span class="block text-xs text-gray-500">Gagné en tout</span><strong>{{ money(referrals.meta.earned_total) }}</strong></div>
            <div class="rounded-lg bg-gray-50 p-2"><span class="block text-xs text-gray-500">Reste à vous verser</span><strong>{{ money(referrals.meta.due) }}</strong></div>
          </div>
          <p v-if="!referrals.data.length" class="text-sm text-gray-500">Aucun pour l'instant. Ils apparaissent ici dès qu'ils se connectent à vous depuis Jibiat Marchand.</p>
          <ul v-else class="divide-y text-sm">
            <li v-for="r in referrals.data" :key="r.id" class="flex flex-wrap items-center justify-between gap-2 py-2">
              <div class="min-w-0">
                <a :href="r.store_url" target="_blank" rel="noopener" class="font-medium hover:underline">{{ r.store_name }}</a>
                <span v-if="r.merchant && r.merchant !== r.store_name" class="text-gray-500"> · {{ r.merchant }}</span>
                <span class="ml-1 rounded px-1.5 py-0.5 text-xs" :class="PLAN[r.plan_status]?.[1] ?? 'bg-gray-100 text-gray-700'">{{ PLAN[r.plan_status]?.[0] ?? r.plan_status }}</span>
              </div>
              <span class="text-gray-600">{{ money(r.earned_total) }} gagnés<template v-if="r.commission_ends_at"> · jusqu'au {{ date(r.commission_ends_at) }}</template></span>
            </li>
          </ul>
          <p class="text-xs text-gray-500">Chiffres envoyés par Jibiat Marchand chaque jour et à chaque paiement. La part revenant à votre entreprise vous est versée par Jibiat Marchand.</p>
        </div>
      </section>
      <SaveBar :dirty="dirty" :saving="saving" :error="error" @save="save" @reset="reset" />
    </template>
  </SettingsPage>
</template>

<script setup>
import SettingsPage from '../../../components/settings/SettingsPage.vue'
import SaveBar from '../../../components/settings/SaveBar.vue'
import DirectoryBlock from '../../../components/settings/blocks/DirectoryBlock.vue'
import MerchantSignupBlock from '../../../components/settings/blocks/MerchantSignupBlock.vue'
import { useCompanySettings } from '../../../composables/useCompanySettings'
import { computed, onMounted, ref, watch } from 'vue'
import QRCode from 'qrcode'
import http from '../../../bootstrap/axios'
import { date, money } from '../../../utils/format'
import { useAuthStore } from '../../../stores/auth'

const auth = useAuthStore()

// Lien d'invitation Jibiat Marchand (un mois d'essai en plus pour les e-commerçants invités)
const inviteUrl = computed(() => auth.user?.company?.jibiat_marchand_invite_url)
const whatsappUrl = computed(() => `https://wa.me/?text=${encodeURIComponent(`Gérez vos commandes, votre stock et votre boutique en ligne avec Jibiat Marchand, et envoyez-nous vos courses en un clic. Un mois d'essai en plus avec ce lien : ${inviteUrl.value}`)}`)
const qr = ref(null)
watch(inviteUrl, async (url) => {
  qr.value = url ? await QRCode.toDataURL(url, { margin: 1, width: 256 }).catch(() => null) : null
}, { immediate: true })

// E-commerçants parrainés et part de leur abonnement (rapportées par Jibiat Marchand)
const PLAN = {
  trialing: ['Essai', 'bg-sky-100 text-sky-800'],
  active: ['Abonné', 'bg-emerald-100 text-emerald-800'],
  past_due: ['Paiement en retard', 'bg-amber-100 text-amber-800'],
  cancelled: ['Résilié', 'bg-gray-100 text-gray-700'],
  expired: ['Expiré', 'bg-gray-100 text-gray-700'],
  none: ['Sans abonnement', 'bg-gray-100 text-gray-700'],
}
const referrals = ref(null)
const ratePercent = computed(() => Math.round((referrals.value?.meta.rate ?? 0.2) * 100))
const months = computed(() => referrals.value?.meta.months ?? 12)
onMounted(async () => {
  try {
    referrals.value = (await http.get('/jibiat-marchand/referrals')).data
  } catch {
    // Liste indisponible : le lien d'invitation reste utilisable
  }
})
const copied = ref(false)
async function copy() {
  try {
    await navigator.clipboard.writeText(inviteUrl.value)
    copied.value = true
    setTimeout(() => (copied.value = false), 2000)
  } catch {
    // Copie refusée par le navigateur : le lien reste sélectionnable
  }
}

const { form, dirty, saving, error, save, reset } = useCompanySettings(['merchant_signup', 'directory_listed', 'directory_pitch'])
</script>
