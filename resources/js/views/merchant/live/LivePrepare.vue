<template>
  <div class="space-y-3.5 pb-28">
    <p v-if="error" class="rounded-2xl bg-red-50 px-4 py-3 text-[15px] text-red-700" role="alert">{{ error }}</p>
    <p v-if="!d && !error" class="m-card p-6 text-center text-slate-500">Chargement…</p>

    <!-- Comment ça marche : en entier aux premières visites -->
    <LiveIntro v-if="d && introOpen" :offer="d.offer" />

    <template v-if="d && !introOpen">
      <!-- Étapes -->
      <div class="flex gap-1.5 text-[13px] font-bold">
        <span v-for="(s, i) in STEPS" :key="s.key" :class="['flex-1 rounded-full py-1.5 text-center', step === s.key || (step === 'nouveau' && s.key === 'produits') ? 'bg-[var(--app-color)] text-white' : i < stepIndex ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-500']">{{ i < stepIndex ? '✓' : i + 1 }} {{ s.label }}</span>
      </div>

      <!-- ── 1. Choisir les produits ── -->
      <template v-if="step === 'produits'">
        <div class="guide">
          <p class="guide-title">Étape 1 sur 3</p>
          <p>1. Cochez vos produits.</p>
          <p>2. Produit absent ? Prenez-le en photo.</p>
          <p>3. Appuyez sur « Continuer ».</p>
        </div>

        <LiveIntro v-if="d.intro && !d.intro.full" :offer="d.offer" folded />

        <p v-if="d.offer.offered_by_company" class="rounded-2xl bg-emerald-50 px-4 py-3 text-[15px] text-emerald-800">Votre agence vous offre vos lives.</p>
        <p v-else-if="d.offer.trial_left" class="rounded-2xl bg-emerald-50 px-4 py-3 text-[15px] text-emerald-800">Votre premier live est offert.</p>
        <p v-if="d.offer.extra_cards" class="rounded-2xl bg-indigo-50 px-4 py-3 text-[15px] text-indigo-900">Il vous reste <b>{{ d.offer.extra_cards }} fiches IA</b> (photo + IA). Ajouter un produit à la main reste toujours gratuit.</p>

        <button type="button" class="tap flex w-full items-center justify-center gap-2 rounded-2xl border-2 border-dashed border-[var(--app-color)] bg-white py-3.5 text-[16px] font-bold text-[var(--app-color)]" @click="openNew">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 8h3l2-3h6l2 3h3v11H4z" /><circle cx="12" cy="13" r="3.5" /></svg>
          Nouveau produit : photo + IA
        </button>

        <label class="m-card flex items-center gap-2 px-3">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7" /><path d="M20 20l-3.5-3.5" /></svg>
          <input v-model="search" class="min-h-[46px] flex-1 bg-transparent text-[16px] outline-none" placeholder="Chercher dans mon catalogue" aria-label="Chercher un produit">
        </label>

        <div class="m-card divide-y divide-slate-100 overflow-hidden">
          <p class="px-4 py-2.5 text-[13px] font-semibold text-slate-500">MON CATALOGUE · {{ selected.length }} choisi{{ selected.length > 1 ? 's' : '' }}</p>
          <label v-for="p in shownCatalog" :key="p.id" :class="['flex min-h-[60px] items-center gap-3 px-4 py-2.5', p.stock === 0 ? 'opacity-50' : '']">
            <input type="checkbox" class="h-6 w-6 shrink-0 accent-[var(--app-color)]" :checked="isSelected(p.id)" :disabled="p.stock === 0" @change="toggle(p)">
            <img v-if="p.photo_url" :src="p.photo_url" alt="" class="h-12 w-12 shrink-0 rounded-xl object-cover">
            <span v-else class="grid h-12 w-12 shrink-0 place-items-center rounded-xl bg-red-50 text-[18px]" aria-hidden="true">📷</span>
            <span class="min-w-0 flex-1">
              <b class="block truncate text-[15px]">{{ p.name }}</b>
              <span v-if="!p.photo_url" class="mr-1.5 inline-block rounded-md bg-red-50 px-1.5 text-[12px] font-bold text-red-700">sans photo</span>
              <span class="text-[14px] text-slate-500">{{ p.stock === null ? 'stock non suivi' : p.stock === 0 ? 'Plus en stock' : `${p.stock} en stock` }} · {{ money(p.price) }}</span>
            </span>
          </label>
          <p v-if="!d.catalog.length" class="p-6 text-center text-[15px] text-slate-500">Votre catalogue est vide : ajoutez vos produits en photo.</p>
        </div>

        <!-- Produits du live : prix et quantité -->
        <div v-if="selected.length" class="m-card divide-y divide-slate-100">
          <p class="px-4 py-2.5 text-[13px] font-semibold text-slate-500">PRIX ET QUANTITÉ DU LIVE</p>
          <div v-for="(r, idx) in selected" :key="r.key" class="px-4 py-2.5">
            <!-- Photo obligatoire : elle est enregistrée sur le produit du catalogue -->
            <div v-if="!r.photo_url && isFirstOf(idx)" class="mb-2 rounded-xl bg-red-50 p-2.5 text-red-800">
              <p class="text-[14px] font-bold">📷 Photo manquante : elle est obligatoire pour la carte.</p>
              <p v-if="uploadingFor === r.product_id" class="mt-1.5 text-[14px] font-semibold">Envoi de la photo…</p>
              <div v-else class="mt-1.5 grid grid-cols-2 gap-2">
                <label class="tap flex cursor-pointer items-center justify-center rounded-lg bg-white py-2 text-[14px] font-bold text-slate-800 ring-1 ring-red-200">
                  Prendre la photo
                  <input type="file" accept="image/*" capture="environment" class="sr-only" @change="productPhoto(r.product_id, $event)">
                </label>
                <label class="tap flex cursor-pointer items-center justify-center rounded-lg bg-white py-2 text-[14px] font-bold text-slate-800 ring-1 ring-red-200">
                  Depuis la galerie
                  <input type="file" accept="image/*" class="sr-only" @change="productPhoto(r.product_id, $event)">
                </label>
              </div>
            </div>
            <div class="flex items-center gap-2">
              <img v-if="r.photo_url && isFirstOf(idx)" :src="r.photo_url" alt="" class="h-9 w-9 shrink-0 rounded-lg object-cover">
              <span class="min-w-0 flex-1 truncate text-[15px] font-semibold">{{ r.name }}</span>
              <label class="sr-only" :for="`price-${r.key}`">Prix de {{ r.name }}</label>
              <input :id="`price-${r.key}`" v-model.number="r.price" type="number" min="0" step="500" class="m-input !min-h-[42px] w-28 text-right">
              <label class="sr-only" :for="`qty-${r.key}`">Quantité de {{ r.name }}</label>
              <input :id="`qty-${r.key}`" v-model.number="r.quantity" type="number" min="1" class="m-input !min-h-[42px] w-16 text-right">
            </div>
            <!-- Tailles ou couleurs : chacune a son code sur la carte -->
            <div v-if="hasVariants(r.product_id)" class="mt-1.5 flex items-center gap-2">
              <input v-model="r.variant" maxlength="40" class="m-input !min-h-[42px] flex-1" placeholder="Taille ou couleur (ex. M, Rouge)" :aria-label="`Taille ou couleur de ${r.name}`">
              <button type="button" class="grid h-[42px] w-[42px] shrink-0 place-items-center rounded-xl bg-slate-100 text-[18px] text-slate-600" :aria-label="`Retirer cette taille de ${r.name}`" @click="removeRow(idx)">✕</button>
            </div>
            <button v-if="isLastOf(idx)" type="button" class="mt-1.5 text-[15px] font-bold text-[var(--app-color)]" @click="addVariant(idx)">＋ Taille ou couleur</button>
          </div>
        </div>
      </template>

      <!-- ── Nouveau produit : photo + IA ── -->
      <template v-if="step === 'nouveau'">
        <div class="guide">
          <p class="guide-title">Nouveau produit</p>
          <p>1. Prenez la photo (bien éclairée) ou choisissez-la dans la galerie.</p>
          <p>2. L'IA donne le nom. Vérifiez-le.</p>
          <p>3. Mettez le prix, puis « Ajouter au live ».</p>
          <p class="text-slate-500">L'IA est facultative : vous pouvez aussi écrire le nom vous-même, c'est gratuit.</p>
        </div>

        <!-- Photo du produit : appareil photo, ou une photo déjà dans la galerie -->
        <div class="overflow-hidden rounded-[22px] bg-slate-100">
          <img v-if="preview" :src="preview" alt="Photo du produit" class="h-52 w-full object-cover">
          <div class="grid grid-cols-2 gap-2 p-2">
            <label class="tap flex cursor-pointer flex-col items-center justify-center gap-1.5 rounded-2xl bg-white py-4 text-[15px] font-bold text-slate-700 shadow-sm">
              <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 8h3l2-3h6l2 3h3v11H4z" /><circle cx="12" cy="13" r="3.5" /></svg>
              {{ preview ? 'Reprendre la photo' : 'Prendre la photo' }}
              <input type="file" accept="image/*" capture="environment" class="sr-only" @change="onPhoto">
            </label>
            <label class="tap flex cursor-pointer flex-col items-center justify-center gap-1.5 rounded-2xl bg-white py-4 text-[15px] font-bold text-slate-700 shadow-sm">
              <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2" /><circle cx="9" cy="10" r="2" /><path d="m21 16-5-5-9 9" /></svg>
              Depuis la galerie
              <input type="file" accept="image/*" class="sr-only" @change="onPhoto">
            </label>
          </div>
        </div>

        <!-- Photo choisie : l'IA ne travaille que si on le demande (une fiche IA), sinon tout à la main, gratuit -->
        <div v-if="preview && !ai && !recognizing && !needPack" class="rounded-2xl bg-indigo-50 px-4 py-3 text-[15px] text-indigo-900">
          <template v-if="cardsLeft > 0">
            <button type="button" class="block w-full rounded-xl bg-indigo-600 py-3 text-[16px] font-bold text-white" @click="analyze">✨ Remplir avec l'IA (1 fiche)</button>
            <span class="mt-1.5 block text-center text-[14px]">Il vous reste {{ cardsLeft }} fiche{{ cardsLeft > 1 ? 's' : '' }} IA. Ou écrivez le nom vous-même ci-dessous, c'est gratuit.</span>
          </template>
          <template v-else>
            <span class="block font-bold">Vous n'avez plus de fiches IA.</span>
            <span class="mt-1 block">Pas obligé d'en acheter : écrivez le nom ci-dessous, c'est gratuit.</span>
            <button type="button" class="mt-2 block w-full rounded-xl bg-amber-600 py-2.5 font-bold text-white" @click="buyPack">Acheter {{ d.offer.prices.pack_cards }} fiches pour {{ money(d.offer.prices.pack) }}</button>
          </template>
        </div>
        <div v-if="recognizing" class="rounded-2xl bg-indigo-50 px-4 py-3 text-[15px] font-semibold text-indigo-800">L'IA regarde la photo…</div>
        <div v-else-if="ai" class="rounded-2xl border-2 border-indigo-500 bg-indigo-50 px-4 py-3">
          <p class="text-[14px] font-bold text-indigo-700">L'IA a reconnu le produit</p>
          <p class="text-[18px] font-extrabold text-indigo-950">{{ ai.name }}</p>
          <p class="text-[14px] text-indigo-800">Fiches IA restantes : {{ ai.cards.left }}</p>
          <button v-if="np.token" type="button" class="mt-1 text-[14px] font-bold text-indigo-700 underline" @click="analyze">Pas le bon nom ? Analyser de nouveau (gratuit)</button>
        </div>
        <div v-if="needPack" class="rounded-2xl bg-amber-50 px-4 py-3 text-[15px] text-amber-900">
          Vous n'avez plus de fiches IA.
          <span class="mt-1 block font-bold">Pas obligé d'en acheter : écrivez le nom ci-dessous, c'est gratuit.</span>
          <button type="button" class="mt-2 block w-full rounded-xl bg-amber-600 py-2.5 font-bold text-white" @click="buyPack">Acheter {{ d.offer.prices.pack_cards }} fiches pour {{ money(d.offer.prices.pack) }}</button>
          <span class="mt-1 block text-[13px]">Les fiches achetées restent valables pour vos prochains lives.</span>
        </div>

        <div class="space-y-3">
          <label class="block text-[14px] font-semibold text-slate-600">Nom du produit
            <input v-model="np.name" class="m-input mt-1 w-full">
          </label>
          <label class="block text-[14px] font-semibold text-slate-600">Description courte
            <textarea v-model="np.description" rows="2" class="m-input mt-1 w-full !py-2" />
          </label>
          <div class="grid grid-cols-3 gap-2">
            <label class="block text-[14px] font-semibold text-slate-600">Prix d'achat
              <input v-model.number="np.cost" type="number" min="0" step="500" class="m-input mt-1 w-full">
            </label>
            <label class="block text-[14px] font-semibold text-slate-600">Prix du live
              <input v-model.number="np.price" type="number" min="0" step="500" class="m-input mt-1 w-full !ring-2 !ring-slate-900">
            </label>
            <label class="block text-[14px] font-semibold text-slate-600">Quantité
              <input v-model.number="np.quantity" type="number" min="1" class="m-input mt-1 w-full">
            </label>
          </div>
          <p v-if="np.price && np.cost" :class="['text-[15px]', np.price > np.cost ? 'text-slate-700' : 'text-red-700']">
            <b>Il vous reste {{ money(np.price - np.cost) }} par pièce.</b>
            <template v-if="ai?.price_range"> Vendu entre {{ money(ai.price_range.min) }} et {{ money(ai.price_range.max) }} dans d'autres boutiques.</template>
          </p>
          <label class="flex items-center gap-2 text-[15px]"><input v-model="np.keep_in_catalog" type="checkbox" class="h-5 w-5"> Garder aussi dans mon catalogue</label>
        </div>
        <p v-if="!photoFile" class="text-center text-[14px] font-semibold text-red-700">📷 Prenez ou choisissez d'abord la photo du produit : elle est obligatoire pour la carte.</p>
        <button type="button" class="m-btn-primary w-full" :disabled="!photoFile || !np.name || !np.price || !np.quantity || saving" @click="saveProduct(false)">Ajouter au live</button>
        <button type="button" class="m-btn-secondary w-full" :disabled="!photoFile || !np.name || !np.price || !np.quantity || saving" @click="saveProduct(true)">Ajouter et prendre un autre produit</button>
        <button type="button" class="w-full py-2 text-[15px] font-semibold text-slate-500" @click="step = 'produits'">Annuler</button>
      </template>

      <!-- ── 2. Carte ── -->
      <template v-if="step === 'carte'">
        <div class="guide">
          <p class="guide-title">Étape 2 sur 3</p>
          <p>1. Donnez un nom à votre live.</p>
          <p>2. Téléchargez la carte.</p>
          <p>3. Montrez-la pendant le live.</p>
        </div>
        <!-- Commandes automatiques : numéro WhatsApp officiel, ou WhatsApp de la boutique relié (option de la console) -->
        <LiveWhatsAppConnect :initial="d.whatsapp" :default-phone="d.offer.phone" @connected="onWhatsApp" />

        <label class="block text-[14px] font-semibold text-slate-600">Nom du live
          <input v-model="liveName" class="m-input mt-1 w-full !text-[17px] !font-semibold" @change="saveItems">
        </label>
        <div class="flex gap-2">
          <button type="button" :class="['flex-1 rounded-xl py-2.5 text-[15px] font-bold', !square ? 'bg-slate-900 text-white' : 'bg-white text-slate-700 ring-1 ring-slate-300']" @click="square = false">Vertical (TikTok)</button>
          <button type="button" :class="['flex-1 rounded-xl py-2.5 text-[15px] font-bold', square ? 'bg-slate-900 text-white' : 'bg-white text-slate-700 ring-1 ring-slate-300']" @click="square = true">Carré (Facebook)</button>
        </div>
        <div class="space-y-3">
          <div v-for="c in cards" :key="c.letter" class="space-y-2">
            <img v-if="c.url" :src="c.url" :alt="`Carte ${c.letter} du live`" class="mx-auto w-full max-w-[320px] rounded-2xl shadow-soft">
            <p v-else class="m-card p-6 text-center text-slate-500">Préparation de la carte {{ c.letter }}…</p>
          </div>
        </div>
        <p class="rounded-2xl bg-amber-50 px-4 py-3 text-[15px] text-amber-900">La carte montre seulement vos produits. <b>Pendant le live, dites votre numéro WhatsApp</b> (ou mettez-le dans votre bio) : les clientes y envoient le code du produit.</p>

        <div class="m-card space-y-1.5 border-2 border-blue-900 p-4 text-[16px]">
          <p class="text-[17px] font-extrabold">Montrer la carte</p>
          <p><b>TikTok :</b> effet « Écran vert » avec la carte, ou montrez-la sur un 2e téléphone.</p>
          <p><b>Facebook :</b> publiez la carte et épinglez-la.</p>
        </div>
        <p class="text-center text-[14px] text-slate-500">{{ priceLine }}</p>
      </template>
    </template>

    <!-- Bouton du bas -->
    <div v-if="d && introOpen" class="fixed inset-x-0 bottom-0 z-20 border-t border-slate-200 bg-white px-4 py-3 pb-[max(.75rem,env(safe-area-inset-bottom))]">
      <button type="button" class="m-btn-primary w-full" @click="closeIntro">Préparer mon live</button>
      <p class="mt-1.5 text-center text-[12px] text-slate-400">Propulsé par <b>Jibiat</b></p>
    </div>
    <div v-if="d && !introOpen && step !== 'nouveau'" class="fixed inset-x-0 bottom-0 z-20 border-t border-slate-200 bg-white px-4 py-3 pb-[max(.75rem,env(safe-area-inset-bottom))]">
      <p v-if="step === 'produits' && noPhoto.length" class="mb-2 text-center text-[14px] font-semibold text-red-700">{{ noPhoto.length }} produit{{ noPhoto.length > 1 ? 's' : '' }} sans photo : ajoutez {{ noPhoto.length > 1 ? 'leur' : 'sa' }} photo pour créer la carte.</p>
      <button v-if="step === 'produits'" type="button" class="m-btn-primary w-full" :disabled="!selected.length || noPhoto.length > 0 || saving" @click="toCard">Continuer avec {{ selected.length }} article{{ selected.length > 1 ? 's' : '' }}</button>
      <div v-else class="flex gap-2">
        <button type="button" class="h-[52px] w-[52px] shrink-0 rounded-2xl bg-slate-100 text-2xl font-bold text-slate-700" aria-label="Revenir aux produits" @click="step = 'produits'">‹</button>
        <button type="button" class="m-btn-primary flex-1" :disabled="saving" @click="downloadAndStart">{{ mustPay ? `Télécharger la carte et payer ${money(d.offer.live_price)}` : 'Télécharger la carte et ouvrir mon live' }}</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../../../bootstrap/axios'
import { useToastStore } from '../../../stores/toasts'
import { money } from '../../../utils/format'
import { drawCard, saveCard } from './liveCard'
import LiveIntro from './LiveIntro.vue'
import LiveWhatsAppConnect from './LiveWhatsAppConnect.vue'

/** Préparer mon live : produits (catalogue ou photo + IA), carte à télécharger, démarrage */
const STEPS = [{ key: 'produits', label: 'Produits' }, { key: 'carte', label: 'Carte' }, { key: 'live', label: 'Live' }]
const router = useRouter()
const toasts = useToastStore()
const d = ref(null)
const error = ref('')
const step = ref('produits')
const search = ref('')
const selected = ref([])
const saving = ref(false)
const liveName = ref('')
const square = ref(false)
const cards = ref([])

const stepIndex = computed(() => ({ produits: 0, nouveau: 0, carte: 1 })[step.value])
const shownCatalog = computed(() => {
  const q = search.value.trim().toLowerCase()
  return (d.value?.catalog || []).filter((p) => !q || p.name.toLowerCase().includes(q))
})
const isSelected = (id) => selected.value.some((r) => r.product_id === id)
// Live payé à l'unité, en ligne : le bouton mène au paiement
const mustPay = computed(() => !!d.value?.offer?.pay_online && d.value.offer.next_plan === 'single')
const priceLine = computed(() => {
  const o = d.value?.offer
  if (!o) return ''
  if (o.offered_by_company) return 'Ce live vous est offert par votre agence.'
  if (o.monthly_until) return 'Compris dans votre forfait du mois.'
  if (o.trial_left) return 'Votre premier live est offert.'
  if (o.next_plan === 'capped') return `Vous avez atteint ${money(o.prices.cap)} ce mois-ci : vos lives sont gratuits jusqu'à la fin du mois.`
  const cap = o.prices.cap ? ` Jamais plus de ${money(o.prices.cap)} par mois.` : ''
  return o.pay_online
    ? `Ce live : ${money(o.live_price)}, à payer par Orange Money, MTN, Moov, Wave ou carte.${cap}`
    : `Ce live : ${money(o.live_price)}, retenus sur votre prochain reversement.${cap}`
})

function fill(data) {
  // Compteur de « Comment ça marche » : seulement dans la réponse de « prepare »
  data.intro ??= d.value?.intro
  d.value = data
  liveName.value = data.live.name
  selected.value = data.items.map((i) => row({ product_id: i.product_id, name: i.name, variant: i.variant || '', price: i.price, quantity: i.quantity, code: i.code, photo_url: i.photo_url }))
  if (data.live.status === 'live') router.replace(`/marchand/live/${data.live.id}`)
}

// « Comment ça marche » en entier aux premières visites (compté sur le serveur, par compte)
const introOpen = ref(false)
let introChecked = false
async function closeIntro() {
  introOpen.value = false
  window.scrollTo({ top: 0 })
  try {
    d.value.intro = (await http.post('/live/intro-seen')).data.data
  } catch {
    // compteur non enregistré : la page reviendra à la prochaine visite
  }
}

async function load() {
  try {
    fill((await http.post('/live/prepare')).data.data)
    if (!introChecked) {
      introChecked = true
      introOpen.value = !!d.value.intro?.full
    }
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

let rowKey = 0
const row = (r) => ({ ...r, key: ++rowKey })

function toggle(p) {
  selected.value = isSelected(p.id)
    ? selected.value.filter((r) => r.product_id !== p.id)
    : [...selected.value, row({ product_id: p.id, name: p.name, variant: '', price: p.price, quantity: p.stock || 1, photo_url: p.photo_url })]
}

// Variantes : une ligne par taille ou couleur, juste sous le produit
const hasVariants = (productId) => selected.value.filter((r) => r.product_id === productId).length > 1
const isFirstOf = (idx) => selected.value[idx - 1]?.product_id !== selected.value[idx].product_id
// Produits choisis sans photo : la carte ne peut pas être créée
const noPhoto = computed(() => [...new Set(selected.value.filter((r) => !r.photo_url).map((r) => r.product_id))])
const uploadingFor = ref(null)

async function productPhoto(productId, event) {
  const file = event.target.files?.[0]
  event.target.value = ''
  if (!file) return
  uploadingFor.value = productId
  try {
    const body = new FormData()
    body.append('photo', await shrinkPhoto(file))
    const { photo_url: url } = (await http.post(`/live/products/${productId}/photo`, body)).data.data
    for (const r of selected.value) if (r.product_id === productId) r.photo_url = url
    const p = d.value.catalog.find((c) => c.id === productId)
    if (p) p.photo_url = url
    toasts.success('Photo ajoutée.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    uploadingFor.value = null
  }
}
const isLastOf = (idx) => selected.value[idx + 1]?.product_id !== selected.value[idx].product_id
function addVariant(idx) {
  const r = selected.value[idx]
  selected.value.splice(idx + 1, 0, row({ ...r, variant: '', quantity: 1 }))
}
function removeRow(idx) {
  selected.value.splice(idx, 1)
}

async function saveItems() {
  const { data } = await http.put(`/live/${d.value.live.id}/items`, {
    name: liveName.value,
    items: selected.value.map((r) => ({ product_id: r.product_id, variant: hasVariants(r.product_id) ? r.variant.trim() || null : null, price: r.price, quantity: r.quantity })),
  })
  fill(data.data)
}

async function toCard() {
  if (noPhoto.value.length) {
    toasts.error('Ajoutez la photo de chaque produit pour créer la carte.')
    return
  }
  const missing = selected.value.find((r) => hasVariants(r.product_id) && !r.variant.trim())
  if (missing) {
    toasts.error(`${missing.name} : écrivez la taille ou la couleur de chaque ligne.`)
    return
  }
  saving.value = true
  try {
    await saveItems()
    step.value = 'carte'
    await renderCards()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    saving.value = false
  }
}

// Une carte par lettre (A1…A9, B1…)
async function renderCards() {
  const groups = {}
  for (const i of d.value.items) (groups[i.code[0]] ||= []).push(i)
  cards.value = Object.keys(groups).map((letter) => ({ letter, url: null, canvas: null }))
  for (const c of cards.value) {
    c.canvas = await drawCard({ items: groups[c.letter], square: square.value })
    c.url = c.canvas.toDataURL('image/png')
  }
}
watch(square, () => { if (step.value === 'carte') renderCards() })

// WhatsApp relié : mise à jour de l'écran
async function onWhatsApp() {
  await load()
  await renderCards()
}

async function downloadAndStart() {
  saving.value = true
  try {
    await saveItems()
    await renderCards()
    for (const c of cards.value) await saveCard(c.canvas, `live-carte-${c.letter}.png`)
    const { data } = await http.post(`/live/${d.value.live.id}/start`)
    // Live payé en ligne : page de paiement de PayDunya, retour sur l'application une fois payé
    if (data.data.payment_url) {
      window.location.assign(data.data.payment_url)
      return
    }
    router.push(`/marchand/live/${data.data.live.id}`)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    saving.value = false
  }
}

// ── Nouveau produit ──
const preview = ref(null)
const photoFile = ref(null)
const ai = ref(null)
const recognizing = ref(false)
const needPack = ref(false)
const np = reactive({ name: '', description: '', category: '', cost: null, price: null, quantity: 1, keep_in_catalog: true, token: null })

function openNew() {
  Object.assign(np, { name: '', description: '', category: '', cost: null, price: null, quantity: 1, keep_in_catalog: true, token: null })
  preview.value = null
  photoFile.value = null
  ai.value = null
  needPack.value = false
  step.value = 'nouveau'
}

async function onPhoto(event) {
  const file = event.target.files?.[0]
  // Le même fichier pourra être choisi de nouveau
  event.target.value = ''
  if (!file) return
  photoFile.value = await shrinkPhoto(file)
  preview.value = URL.createObjectURL(photoFile.value)
  // Nouvelle photo : rien n'est analysé tant que la vendeuse ne le demande pas
  ai.value = null
  needPack.value = false
}

/**
 * Photo réduite (1600 px au plus, JPEG) : envoi plus rapide sur le réseau mobile, et l'IA refuse les images
 * trop lourdes des téléphones récents. En cas de souci, la photo d'origine.
 */
async function shrinkPhoto(file, max = 1600) {
  try {
    const img = await createImageBitmap(file, { imageOrientation: 'from-image' })
    const k = Math.min(1, max / Math.max(img.width, img.height))
    if (k === 1 && file.size < 1_500_000) return file
    const canvas = document.createElement('canvas')
    canvas.width = Math.round(img.width * k)
    canvas.height = Math.round(img.height * k)
    canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height)
    const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.85))
    return blob ? new File([blob], 'produit.jpg', { type: 'image/jpeg' }) : file
  } catch {
    return file
  }
}

// Fiches IA restantes (fiches offertes, recharges, forfait)
const cardsLeft = computed(() => d.value?.cards?.left ?? 0)

async function analyze() {
  if (!photoFile.value) return
  recognizing.value = true
  needPack.value = false
  try {
    const body = new FormData()
    body.append('photo', photoFile.value)
    if (np.token) body.append('token', np.token)
    const { data } = await http.post(`/live/${d.value.live.id}/recognize`, body)
    ai.value = data.data
    if (data.data.cards) d.value.cards = data.data.cards
    Object.assign(np, { name: data.data.name, description: data.data.description, category: data.data.category, token: data.data.token })
  } catch (e) {
    const message = apiErrorMessage(e)
    if (e.response?.data?.errors?.cards) needPack.value = true
    else toasts.error(message)
  } finally {
    recognizing.value = false
  }
}

async function buyPack() {
  try {
    const data = (await http.post('/live/pack')).data.data
    if (data.payment_url) {
      window.location.assign(data.payment_url)
      return
    }
    d.value.offer = data
    d.value.cards = { ...d.value.cards, left: (d.value.cards?.left ?? 0) + Number(data.prices?.pack_cards ?? 0) }
    needPack.value = false
    toasts.success('Fiches ajoutées. Touchez « Remplir avec l\'IA ».')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function saveProduct(another) {
  saving.value = true
  try {
    await saveItems()
    const body = new FormData()
    for (const k of ['name', 'description', 'category', 'price', 'quantity']) if (np[k] !== null && np[k] !== '') body.append(k, np[k])
    body.append('keep_in_catalog', np.keep_in_catalog ? '1' : '0')
    if (photoFile.value) body.append('photo', photoFile.value)
    const { data } = await http.post(`/live/${d.value.live.id}/products`, body)
    fill(data.data)
    toasts.success(`${np.name} ajouté au live.`)
    if (another) openNew()
    else step.value = 'produits'
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<style scoped>
.guide {
  border-radius: 18px;
  background: #1e40af;
  color: #fff;
  padding: 14px 16px;
  font-size: 18px;
  font-weight: 700;
  line-height: 1.35;
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.guide-title {
  font-size: 14px;
  font-weight: 800;
  color: #bfdbfe;
  text-transform: uppercase;
  letter-spacing: .04em;
}
</style>
