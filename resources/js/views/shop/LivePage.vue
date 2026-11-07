<template>
  <!-- Lien du live (/l/{jeton}) : les clients voient les produits du live et commandent sans WhatsApp -->
  <div class="min-h-dvh bg-slate-50 pb-10 text-slate-900" :style="{ '--app-color': '#e11d48' }">
    <div v-if="error" class="mx-auto max-w-md space-y-2 px-4 py-24 text-center">
      <p class="text-4xl" aria-hidden="true">🎥</p>
      <p class="text-lg font-semibold">{{ error }}</p>
    </div>

    <p v-else-if="!live" class="py-24 text-center text-slate-500">Chargement…</p>

    <!-- Commande envoyée -->
    <div v-else-if="done" class="mx-auto max-w-md space-y-4 px-4 py-12 text-center">
      <p class="text-5xl" aria-hidden="true">{{ done.status === 'waiting' ? '⏳' : '✅' }}</p>
      <h1 class="text-2xl font-extrabold">{{ done.status === 'waiting' ? 'Vous êtes sur la liste d\'attente' : 'Commande envoyée !' }}</h1>
      <p class="text-[16px] text-slate-600">
        {{ done.status === 'waiting'
          ? `Ce produit est épuisé pour le moment. ${live.shop} vous recontacte s'il revient.`
          : `${live.shop} a bien reçu votre commande. Le livreur vous appellera pour la livraison. Paiement à la livraison.` }}
      </p>
      <button type="button" class="m-btn-primary w-full" @click="reset">Commander autre chose</button>
    </div>

    <template v-else>
      <header class="bg-slate-900 text-white">
        <div class="mx-auto max-w-3xl space-y-1 px-4 pb-5 pt-7">
          <p class="flex items-center gap-2 text-[13px] font-bold">
            <span v-if="live.status === 'live'" class="inline-flex items-center gap-1.5 rounded-full bg-rose-600 px-2.5 py-0.5"><i class="block h-2 w-2 animate-pulse rounded-full bg-white" />EN DIRECT</span>
            <span v-else class="rounded-full bg-white/15 px-2.5 py-0.5">{{ live.status === 'ended' ? 'Live terminé' : 'Bientôt en live' }}</span>
          </p>
          <h1 class="text-2xl font-extrabold">{{ live.shop }}</h1>
          <p class="text-[15px] text-slate-300">💵 Paiement à la livraison · Livré par {{ live.delivery_company.name }}</p>
        </div>
      </header>

      <main class="mx-auto max-w-3xl space-y-4 px-4 py-5">
        <p v-if="live.status !== 'live'" class="rounded-2xl bg-amber-50 px-4 py-3 text-[15px] text-amber-900">
          {{ live.status === 'ended' ? 'Ce live est terminé : les commandes sont fermées.' : 'Le live n\'a pas encore commencé : vous pourrez commander dès qu\'il démarre.' }}
        </p>
        <p v-else class="text-[15px] font-semibold text-slate-700">Touchez le produit que vous voulez.</p>

        <ul class="grid grid-cols-2 gap-3 md:grid-cols-3">
          <li v-for="i in live.items" :key="i.code">
            <button type="button" :class="['m-card flex w-full flex-col overflow-hidden text-left', picked?.code === i.code ? 'ring-4 ring-[var(--app-color)]' : '']" :disabled="live.status !== 'live'" @click="pick(i)">
              <span class="relative grid aspect-square w-full place-items-center bg-slate-100">
                <img v-if="i.photo_url" :src="i.photo_url" :alt="i.name" class="h-full w-full object-cover" loading="lazy">
                <span v-else class="text-4xl font-extrabold text-slate-500">{{ i.code }}</span>
                <span v-if="i.photo_url" class="absolute left-2 top-2 rounded-lg bg-slate-900 px-2 py-0.5 text-[15px] font-extrabold text-white">{{ i.code }}</span>
              </span>
              <span class="flex flex-col gap-0.5 p-3">
                <span class="font-medium leading-snug">{{ i.name }}<template v-if="i.variant"> · {{ i.variant }}</template></span>
                <span class="text-[17px] font-extrabold">{{ money(i.price) }}</span>
                <span v-if="i.remaining === 0" class="text-[13px] font-bold text-red-600">Épuisé : liste d'attente</span>
                <span v-else-if="i.remaining <= 3" class="text-[13px] font-bold text-amber-700">Plus que {{ i.remaining }}</span>
              </span>
            </button>
          </li>
        </ul>

        <!-- Formulaire de commande -->
        <form v-if="picked" ref="formEl" class="m-card space-y-3 p-4" @submit.prevent="send">
          <p class="text-[17px] font-extrabold">{{ picked.code }} · {{ picked.name }}<template v-if="picked.variant"> · {{ picked.variant }}</template></p>
          <div class="flex items-center gap-3">
            <span class="m-label !mb-0">Quantité</span>
            <button type="button" class="tap h-10 w-10 rounded-xl bg-slate-100 text-xl font-bold" aria-label="Une de moins" :disabled="form.quantity <= 1" @click="form.quantity--">−</button>
            <span class="w-6 text-center text-[18px] font-bold" aria-live="polite">{{ form.quantity }}</span>
            <button type="button" class="tap h-10 w-10 rounded-xl bg-slate-100 text-xl font-bold" aria-label="Une de plus" :disabled="form.quantity >= 20" @click="form.quantity++">+</button>
            <span class="ml-auto text-[17px] font-extrabold">{{ money(picked.price * form.quantity) }}</span>
          </div>
          <div>
            <label class="m-label" for="live-name">Votre nom</label>
            <input id="live-name" v-model.trim="form.name" class="m-input" autocomplete="name" required maxlength="120">
          </div>
          <div>
            <label class="m-label" for="live-phone">Votre téléphone</label>
            <input id="live-phone" v-model.trim="form.phone" class="m-input" type="tel" inputmode="tel" autocomplete="tel" required placeholder="07 00 00 00 00">
          </div>
          <div>
            <label class="m-label" for="live-zone">Commune ou quartier</label>
            <SearchSelect
              id="live-zone" v-model="form.zone_id" input-class="m-input" placeholder="Choisissez…" :allow-empty="false" :empty-value="null" required search-placeholder="Commune, quartier…"
              :options="live.zones.map((z) => ({ value: z.id, label: z.name }))"
            />
          </div>
          <div>
            <label class="m-label" for="live-address">Précision (facultatif)</label>
            <input id="live-address" v-model.trim="form.address" class="m-input" maxlength="500" placeholder="Ex. : en face de la pharmacie">
          </div>
          <!-- Piège à robots : invisible pour les personnes -->
          <input v-model="form.website" type="text" name="website" tabindex="-1" autocomplete="off" class="absolute -left-[9999px] h-0 w-0 opacity-0" aria-hidden="true">
          <button type="submit" class="m-btn-primary w-full" :disabled="sending || !form.zone_id">{{ sending ? 'Envoi…' : 'Commander' }}</button>
          <p class="text-center text-[13px] text-slate-500">Vous payez à la livraison.</p>
        </form>
      </main>
    </template>
  </div>
</template>

<script setup>
import { nextTick, onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import SearchSelect from '../../components/SearchSelect.vue'
import { useToastStore } from '../../stores/toasts'
import { money } from '../../utils/format'

const route = useRoute()
const toasts = useToastStore()
const token = String(route.params.token || '')
const live = ref(null)
const error = ref('')
const picked = ref(null)
const done = ref(null)
const sending = ref(false)
const formEl = ref(null)
const form = reactive({ quantity: 1, name: '', phone: '', zone_id: null, address: '', website: '' })

async function load() {
  try {
    live.value = (await http.get(`/lives/${token}`)).data.data
  } catch (e) {
    error.value = e.response?.status === 404 ? 'Ce live est introuvable.' : apiErrorMessage(e)
  }
}

async function pick(item) {
  picked.value = item
  form.quantity = 1
  await nextTick()
  formEl.value?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

async function send() {
  sending.value = true
  try {
    done.value = (await http.post(`/lives/${token}/orders`, { ...form, code: picked.value.code })).data.data
    window.scrollTo({ top: 0 })
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    sending.value = false
  }
}

// Nouvelle commande : on garde le nom, le téléphone et le quartier
async function reset() {
  done.value = null
  picked.value = null
  await load()
}

onMounted(load)
</script>
