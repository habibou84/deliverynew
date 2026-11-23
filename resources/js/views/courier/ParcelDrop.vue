<template>
  <div class="space-y-4 pb-36">
    <!-- Terminé -->
    <section v-if="done" class="m-card p-5 space-y-3 text-center">
      <p class="flex items-center justify-center gap-2 text-xl font-bold text-emerald-700"><Icone nom="valider" taille="grande" />{{ done.count }} colis déposé{{ done.count > 1 ? 's' : '' }}</p>
      <p class="text-slate-600">Déposés le {{ dateTime(done.dropped_at) }}. Un agent contrôlera le bac ; vous serez prévenu s'il manque un colis. Sans contrôle, ils sont confirmés au bout de 24 h.</p>
      <RouterLink to="/livreur/caisse" class="m-btn-primary">Retour à ma caisse</RouterLink>
    </section>

    <section v-else-if="!selfDrop" class="m-card p-5 space-y-3 text-center">
      <p class="font-semibold">Le dépôt en libre-service n'est pas activé par votre entreprise.</p>
      <p class="text-slate-600">Remettez vos colis à la caisse lors de votre point.</p>
      <RouterLink to="/livreur/caisse" class="m-btn-primary">Retour à ma caisse</RouterLink>
    </section>

    <template v-else>
      <!-- Étape 1 : la borne -->
      <section class="m-card p-4 space-y-2">
        <p class="flex items-center gap-2 font-semibold"><span :class="stepClass(!!session)">1</span>Scannez la borne du dépôt</p>
        <p v-if="session" class="flex items-center gap-1.5 text-sm text-emerald-700"><Icone nom="valider" taille="petite" />Borne scannée : déposez vos colis dans les {{ minutes }} minutes.</p>
        <template v-else>
          <p class="text-sm text-slate-600">Le QR code est affiché sur l'écran à côté du bac « Retours livreurs ». Il change toutes les 30 secondes : il faut être devant.</p>
          <button class="m-btn-primary" :disabled="busy" @click="scanning = 'kiosk'"><span class="inline-flex items-center justify-center gap-2"><Icone nom="scanner" />Scanner la borne</span></button>
        </template>
      </section>

      <!-- Étape 2 : chaque colis -->
      <section :class="['m-card p-4 space-y-3', session ? '' : 'opacity-50']">
        <p class="flex items-center gap-2 font-semibold"><span :class="stepClass(scanned.length > 0)">2</span>Scannez chaque colis en le posant dans le bac</p>
        <p class="text-sm" :class="scanned.length === parcels.length ? 'text-emerald-700 font-semibold' : 'text-slate-600'">{{ scanned.length }} / {{ parcels.length }} scanné{{ scanned.length > 1 ? 's' : '' }}</p>
        <div class="divide-y rounded-xl border">
          <div v-for="p in parcels" :key="p.id" :class="['flex items-center gap-3 p-3', scanned.includes(p.id) ? 'bg-emerald-50' : '']">
            <Icone :nom="scanned.includes(p.id) ? 'valider' : 'course'" :class="scanned.includes(p.id) ? 'text-emerald-700' : 'text-slate-400'" />
            <div class="min-w-0 flex-1">
              <p class="font-medium truncate">{{ p.recipient_name || p.recipient_phone }}</p>
              <p class="text-xs text-slate-600">{{ p.tracking_code }} · {{ p.merchant }}<span v-if="typed.includes(p.tracking_code)" class="text-amber-700"> · code tapé</span></p>
            </div>
          </div>
        </div>
        <template v-if="session">
          <button class="m-btn-secondary" @click="scanning = 'parcel'"><span class="inline-flex items-center justify-center gap-2"><Icone nom="scanner" />Scanner un colis</span></button>
          <form class="flex gap-2" @submit.prevent="addTyped">
            <input v-model="typing" class="m-input flex-1 font-mono uppercase" placeholder="Étiquette illisible : tapez le code" aria-label="Code du colis">
            <button class="m-btn-secondary !w-auto px-4" :disabled="!typing.trim()">Ajouter</button>
          </form>
          <p class="text-xs text-slate-600">Un colis non scanné reste noté chez vous.</p>
        </template>
      </section>

      <!-- Étape 3 : la photo -->
      <section :class="['m-card p-4 space-y-2', session ? '' : 'opacity-50']">
        <p class="flex items-center gap-2 font-semibold"><span :class="stepClass(!!photo)">3</span>Photo du bac avec vos colis</p>
        <label :class="['m-btn-secondary flex items-center justify-center gap-2', session ? '' : 'pointer-events-none']">
          <Icone nom="photo" />{{ photo ? 'Reprendre la photo' : 'Prendre la photo' }}
          <input type="file" accept="image/*" capture="environment" class="sr-only" :disabled="!session" @change="onPhoto">
        </label>
        <img v-if="preview" :src="preview" alt="Photo du bac" class="w-full rounded-xl">
      </section>

      <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

      <div class="fixed bottom-16 inset-x-0 z-20 bg-white border-t pb-safe">
        <div class="mx-auto max-w-lg p-3">
          <button class="m-btn-success text-lg py-5" :disabled="busy || !session || !scanned.length || !photo" @click="submit">
            <span class="inline-flex items-center justify-center gap-2"><Icone nom="valider" taille="grande" />Déposer {{ scanned.length || '' }} colis</span>
          </button>
        </div>
      </div>
    </template>

    <QrScanner v-if="scanning" :title="scanning === 'kiosk' ? 'QR code de la borne du dépôt' : 'Étiquette du colis'"
               :hint="scanning === 'kiosk' ? 'Visez le QR code affiché sur l\'écran du dépôt.' : 'Visez le QR code de l\'étiquette du colis.'"
               @read="onScan" @close="scanning = null" />
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import QrScanner from '../../components/courier/QrScanner.vue'
import Icone from '../../components/ui/Icone.vue'
import { currentPosition } from '../../composables/useGeolocation'
import { useToastStore } from '../../stores/toasts'
import { useAuthStore } from '../../stores/auth'
import { dateTime } from '../../utils/format'
import { codeFromScan } from '../../utils/parcelReception'

/**
 * Dépôt en libre-service : borne du dépôt scannée (preuve d'être sur place), chaque colis scanné en le posant dans
 * le bac, photo du bac. Les colis passent « déposés, à contrôler » ; un agent contrôle le bac plus tard.
 */
const toasts = useToastStore()
const auth = useAuthStore()
const selfDrop = computed(() => !!auth.user?.company?.parcel_self_drop)
const parcels = ref([])
const session = ref('')
const minutes = ref(30)
const scanned = ref([])
const typed = ref([])
const typing = ref('')
const photo = ref(null)
const preview = ref('')
const scanning = ref(null)
const busy = ref(false)
const error = ref('')
const done = ref(null)

const byCode = computed(() => new Map(parcels.value.map((p) => [p.tracking_code.toUpperCase(), p])))
const stepClass = (ok) => ['grid h-7 w-7 place-items-center rounded-full text-sm font-bold', ok ? 'bg-emerald-600 text-white' : 'bg-slate-200 text-slate-700']

async function load() {
  try {
    const { data } = await http.get('/courier/wallet')
    parcels.value = (data.data.parcels || []).filter((p) => p.due)
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

function add(code, manual = false) {
  const p = byCode.value.get(code)
  if (!p) {
    toasts.error(`${code} : ce colis n'est pas dans votre liste à rendre.`)
    return false
  }
  if (!scanned.value.includes(p.id)) scanned.value.push(p.id)
  if (manual && !typed.value.includes(p.tracking_code)) typed.value.push(p.tracking_code)
  return true
}

async function onScan(text) {
  const kind = scanning.value
  scanning.value = null
  if (kind === 'kiosk') {
    busy.value = true
    error.value = ''
    try {
      const { data } = await http.post('/courier/parcel-drops/start', { kiosk_code: text })
      session.value = data.data.session
      minutes.value = data.data.minutes
      toasts.success('Borne scannée. Scannez maintenant chaque colis.')
    } catch (e) {
      error.value = apiErrorMessage(e)
    } finally {
      busy.value = false
    }
    return
  }
  if (add(codeFromScan(text))) {
    navigator.vibrate?.(80)
    // Colis suivant
    if (scanned.value.length < parcels.value.length) setTimeout(() => { scanning.value = 'parcel' }, 500)
  }
}

function addTyped() {
  if (add(codeFromScan(typing.value), true)) typing.value = ''
}

function onPhoto(event) {
  photo.value = event.target.files?.[0] || null
  if (preview.value) URL.revokeObjectURL(preview.value)
  preview.value = photo.value ? URL.createObjectURL(photo.value) : ''
}

async function submit() {
  busy.value = true
  error.value = ''
  try {
    const form = new FormData()
    form.append('session', session.value)
    scanned.value.forEach((id) => form.append('order_ids[]', id))
    typed.value.forEach((code) => form.append('typed_codes[]', code))
    form.append('photo', photo.value)
    const position = await currentPosition()
    if (position) {
      form.append('lat', position.lat)
      form.append('lng', position.lng)
    }
    const { data } = await http.post('/courier/parcel-drops', form)
    done.value = data.data
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}

onMounted(load)
onBeforeUnmount(() => preview.value && URL.revokeObjectURL(preview.value))
</script>
