<template>
  <div class="space-y-4">
    <section v-if="welcome" class="rounded-2xl p-5 text-white" :style="{ backgroundColor: 'var(--app-color)' }">
      <p class="text-lg font-bold">Dernière étape : où récupérer vos colis ?</p>
      <p class="text-sm opacity-90 mt-1">Nos livreurs trouvent votre boutique du premier coup, et le bon livreur, le plus proche, est envoyé plus vite.</p>
    </section>

    <p v-if="!loaded" class="text-slate-600">Chargement…</p>

    <template v-else>
      <section class="m-card p-4 space-y-3">
        <div>
          <h2 class="flex items-center gap-2 font-semibold"><ColorIcon name="lieu" :size="32" />Position de votre boutique</h2>
          <p v-if="!saved" class="text-sm text-slate-600">Depuis votre boutique, touchez le bouton.</p>
        </div>

        <!-- Position déjà enregistrée (et pas changée) -->
        <div v-if="saved && !changed" :class="['flex items-center gap-3 rounded-2xl px-3.5 py-3', current.pickup_location_source === 'courier' ? 'bg-amber-50' : 'bg-emerald-50']">
          <span :class="['grid h-9 w-9 shrink-0 place-items-center rounded-full font-black text-white', current.pickup_location_source === 'courier' ? 'bg-amber-500' : 'bg-emerald-700']">{{ current.pickup_location_source === 'courier' ? '?' : '✓' }}</span>
          <span class="min-w-0 text-sm">
            <b class="block text-[15.5px]">{{ current.pickup_location_source === 'courier' ? 'Position estimée par nos livreurs' : 'Position enregistrée' }}</b>
            <span :class="current.pickup_location_source === 'courier' ? 'text-amber-800' : 'text-emerald-800'">{{ [place?.name, current.pickup_located_at ? `le ${date(current.pickup_located_at)}` : '', current.pickup_location_source === 'staff' ? 'par l\'agence' : ''].filter(Boolean).join(' · ') }}</span>
            <span v-if="current.pickup_location_source === 'courier'" class="block text-amber-800">Vérifiez-la depuis votre boutique.</span>
          </span>
        </div>

        <LocationPicker v-model="point" button-class="m-btn-primary" :gps-label="saved ? '📍 Refaire avec ma position actuelle' : '📍 Je suis à ma boutique : utiliser ma position'" height="h-56" allow-link confirm :pending="changed">
          <template #found>
            <div>
              <p class="text-[13px] font-extrabold uppercase tracking-wide text-emerald-700">✓ {{ point?.accuracy != null ? 'Position trouvée' : 'Nouvel emplacement' }}</p>
              <p class="text-[19px] font-black leading-tight">{{ naming ? 'Recherche du lieu…' : place?.name || 'Votre repère sur la carte' }}</p>
              <p class="text-[13px] text-emerald-800">{{ [point?.accuracy != null ? `à ${point.accuracy} m près` : '', place?.street].filter(Boolean).join(' · ') }}</p>
            </div>
          </template>
          <template #actions>
            <p v-if="error" class="field-error">{{ error }}</p>
            <button type="button" class="m-btn-primary w-full !bg-none !bg-slate-900" :disabled="saving" @click="save">{{ saving ? 'Enregistrement…' : 'Enregistrer cette position' }}</button>
            <p class="text-center text-[13px] text-slate-600">Pas le bon endroit ? Déplacez le repère sur la carte.</p>
          </template>
        </LocationPicker>
      </section>

      <!-- Facultatif : l'adresse et le repère, pour le livreur -->
      <section v-if="saved" class="m-card p-4 space-y-3">
        <h2 class="font-semibold">🏠 Pour aider le livreur <span class="text-[13px] font-normal text-slate-600">(facultatif)</span></h2>
        <div>
          <label class="m-label" for="pl-address">Adresse</label>
          <input id="pl-address" v-model.trim="address" class="m-input" maxlength="255" placeholder="Ex. : Riviera 2, rue des Jardins">
        </div>
        <div>
          <label class="m-label" for="pl-landmark">Repère</label>
          <input id="pl-landmark" v-model.trim="landmark" class="m-input" maxlength="255" placeholder="Ex. : face à la pharmacie des Jardins, portail bleu">
        </div>
        <button type="button" class="m-btn-secondary" :disabled="!addressChanged || saving" @click="save">{{ saving ? 'Enregistrement…' : addressChanged ? 'Enregistrer l\'adresse' : 'Adresse enregistrée ✓' }}</button>
      </section>
      <RouterLink v-if="welcome" to="/marchand" class="block text-center text-sm text-slate-600 underline">Plus tard</RouterLink>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import ColorIcon from '../../components/mobile/ColorIcon.vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import LocationPicker from '../../components/LocationPicker.vue'
import { useAuthStore } from '../../stores/auth'
import { useToastStore } from '../../stores/toasts'
import { date } from '../../utils/format'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const toasts = useToastStore()

// Après l'inscription : étape facultative, avec « Plus tard »
const welcome = computed(() => !!route.query.bienvenue)
const loaded = ref(false)
const saving = ref(false)
const error = ref('')
const current = ref({})
const point = ref(null)
const address = ref('')
const landmark = ref('')

// Une position enregistrée, et le repère posé ailleurs (pas encore enregistré)
const saved = computed(() => current.value.pickup_lat != null)
const changed = computed(() => !!point.value && (point.value.lat !== current.value.pickup_lat || point.value.lng !== current.value.pickup_lng))
const addressChanged = computed(() => (address.value || '') !== (current.value.pickup_address || '') || (landmark.value || '') !== (current.value.pickup_landmark || ''))

// Nom du lieu (quartier, commune) de la position affichée
const place = ref(null)
const naming = ref(false)
let nameTimer = null
watch(point, (p) => {
  clearTimeout(nameTimer)
  if (!p) return
  naming.value = true
  nameTimer = setTimeout(async () => {
    try {
      place.value = (await http.get('/geo/place', { params: { lat: p.lat, lng: p.lng } })).data.data
      // Adresse encore vide : remplie avec le lieu trouvé
      if (changed.value && !address.value && place.value) address.value = [place.value.name, place.value.street].filter(Boolean).join(', ')
    } catch {
      place.value = null
    } finally {
      naming.value = false
    }
  }, 500)
}, { deep: true })

async function save() {
  const addressOnly = !changed.value
  saving.value = true
  error.value = ''
  try {
    const { data } = await http.put('/merchant/pickup-location', {
      lat: point.value.lat,
      lng: point.value.lng,
      accuracy: point.value.accuracy ?? null,
      pickup_address: address.value || null,
      pickup_landmark: landmark.value || null,
    })
    current.value = data.data
    await auth.fetchUser()
    toasts.success(addressOnly ? 'Adresse enregistrée.' : 'Lieu de ramassage enregistré. Merci !')
    if (welcome.value) router.replace('/marchand')
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  const { data } = await http.get('/merchant/pickup-location')
  current.value = data.data
  if (data.data.pickup_lat != null) point.value = { lat: data.data.pickup_lat, lng: data.data.pickup_lng, accuracy: null }
  address.value = data.data.pickup_address || ''
  landmark.value = data.data.pickup_landmark || ''
  loaded.value = true
})
</script>
