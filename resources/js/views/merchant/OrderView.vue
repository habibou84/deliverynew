<template>
  <div v-if="order" class="-mx-4 pb-24">
    <!-- Où en est le colis : l'icône de l'étape, une phrase complète et le suivi en 4 étapes -->
    <section class="mx-4 rounded-[24px] bg-white p-4 shadow-soft">
      <div class="flex items-center gap-3.5">
        <ColorIcon :name="STAGE_ICONS[stage.group] || 'colis'" :size="60" />
        <div class="min-w-0">
          <span :class="['inline-flex rounded-full px-2.5 py-0.5 text-xs font-bold', stage.badge]">{{ groupLabel }}</span>
          <p class="mt-1 text-[20px] font-extrabold leading-tight text-slate-900">{{ headline }}</p>
          <p v-if="subline" class="mt-0.5 text-[13.5px] text-slate-500">{{ subline }}</p>
        </div>
      </div>
      <ol class="relative mt-4 flex" :aria-label="`Étape ${stage.step} sur 4`">
        <span class="absolute top-[13px] left-[12.5%] right-[12.5%] h-[3px] rounded bg-slate-200" aria-hidden="true">
          <span class="block h-full rounded bg-gradient-to-r from-[#22A06B] to-[#4C8DF6]" :style="{ width: `${Math.min(stage.step - 1, 3) / 3 * 100}%` }" />
        </span>
        <li v-for="(label, n) in STEP_LABELS" :key="label" class="relative z-10 flex flex-1 flex-col items-center gap-1.5 text-center">
          <span :class="['grid h-7 w-7 place-items-center rounded-full', n + 1 < stage.step || (n + 1 === stage.step && stage.step === 4) ? 'bg-[#22A06B] text-white' : n + 1 === stage.step ? 'bg-[#4C8DF6] text-white ring-4 ring-[#DCE9FF]' : 'bg-slate-200']">
            <AppIcon v-if="n + 1 < stage.step || (n + 1 === stage.step && stage.step === 4)" name="check" class="h-3.5 w-3.5" :stroke="3.2" />
            <i v-else :class="['block h-2 w-2 rounded-full', n + 1 === stage.step ? 'bg-white' : 'bg-slate-400']" />
          </span>
          <span :class="['text-[11.5px] font-semibold', n + 1 === stage.step ? 'text-[#1D5BC7]' : n + 1 < stage.step ? 'text-slate-700' : 'text-slate-400']">{{ label }}</span>
        </li>
      </ol>
      <p v-if="plannedDay" class="mt-3 flex items-center gap-2 rounded-xl bg-[#FFF6F7] px-3 py-2 text-sm text-slate-700"><ColorIcon name="calendrier" :size="22" :tile="false" />Livraison prévue le <strong>{{ plannedDay }}</strong></p>
    </section>

    <div class="px-4 pt-3 space-y-3">
      <!-- Échec de livraison : la décision d'abord -->
      <section v-if="order.needs_decision" class="rounded-[22px] bg-[#FFF7EB] p-4 ring-1 ring-[#FFD89A]">
        <div class="flex items-center gap-3">
          <ColorIcon name="aregler" :size="44" />
          <div class="min-w-0"><p class="font-bold">Ce colis attend votre décision</p><p class="text-sm text-slate-600">{{ failureLine }}</p></div>
        </div>
        <button class="m-btn-primary mt-3 !py-3" @click="openDecision()">Décider</button>
      </section>
      <p v-else-if="canChangeDecision" class="rounded-[22px] bg-white p-4 text-sm text-slate-600 shadow-soft">
        Décision prise. <button class="font-bold text-[var(--app-color)]" @click="openDecision()">Changer la décision</button>
      </p>

      <!-- Code à donner au livreur (tant que la course n'est pas terminée) -->
      <section v-if="!isFinal" class="flex items-center justify-between rounded-[22px] bg-white p-4 shadow-soft">
        <div>
          <p class="text-sm text-slate-500">Code de livraison du client</p>
          <p class="font-mono text-2xl font-extrabold tracking-[0.3em]">{{ order.delivery_code }}</p>
        </div>
        <button class="tap rounded-xl bg-white px-3 py-2 text-sm font-bold ring-1 ring-slate-300" @click="copy(order.delivery_code)">Copier</button>
      </section>
      <!-- Étiquette à coller sur le colis avant le passage du livreur -->
      <RouterLink v-if="!isFinal" :to="`/etiquette/${order.id}`" class="tap flex items-center gap-3 rounded-[20px] bg-white p-3 shadow-soft active:scale-[.99] transition">
        <ColorIcon name="etiquette" :size="40" />
        <span class="flex-1 text-[14.5px] font-bold">Imprimer l'étiquette du colis</span>
        <AppIcon name="right" class="h-5 w-5 text-slate-400" />
      </RouterLink>

      <!-- L'argent de la course -->
      <section class="rounded-[22px] bg-white p-4 shadow-soft space-y-1.5 text-sm">
        <p class="flex items-center gap-2 pb-1 text-[15px] font-bold"><ColorIcon name="billets" :size="30" />L'argent de la course</p>
        <div class="flex justify-between"><span class="text-slate-500">{{ collected !== null ? 'Encaissé chez le client' : 'À encaisser chez le client' }}</span><b>{{ money(collected ?? order.amounts.cod_amount) }}</b></div>
        <div class="flex justify-between"><span class="text-slate-500">Livraison, payée par {{ order.amounts.fee_payer === 'recipient' ? 'le client' : 'vous' }}</span><b>− {{ money(order.amounts.total_fees) }}</b></div>
        <div v-if="order.is_shipping" class="flex justify-between">
          <span class="text-slate-500">Expédition</span>
          <b>− {{ order.shipping.fee !== null ? money(order.shipping.fee) : (order.shipping.fee_estimate ? '≈ ' + money(order.shipping.fee_estimate) : 'au réel') }}</b>
        </div>
        <div v-if="expensesTotal" class="flex justify-between"><span class="text-slate-500">Autres frais</span><b>− {{ money(expensesTotal) }}</b></div>
        <div v-if="showNet" class="flex justify-between items-baseline border-t border-slate-100 pt-2">
          <span class="font-bold">{{ order.status === 'delivered' ? 'Pour vous' : 'Pour vous une fois livrée' }}</span>
          <span :class="['text-xl font-extrabold', net < 0 ? 'text-red-600' : 'text-emerald-700']">{{ money(net) }}</span>
        </div>
      </section>

      <!-- Noter le livreur, et la note laissée par le client -->
      <section v-if="order.ratings && (order.ratings.can_rate || order.ratings.merchant || order.ratings.recipient)" class="rounded-[22px] bg-white p-4 shadow-soft space-y-3">
        <div v-if="order.ratings.can_rate || order.ratings.merchant" class="space-y-2">
          <div class="flex items-center gap-3">
            <span class="h-10 w-10 shrink-0 rounded-full bg-emerald-100 text-emerald-800 grid place-items-center font-bold">{{ (courierName || '?')[0] }}</span>
            <span class="min-w-0"><b class="block truncate">{{ courierName || 'Le livreur' }}</b><span class="text-sm text-slate-500">a livré ce colis</span></span>
          </div>
          <template v-if="order.ratings.merchant">
            <p class="flex items-center gap-2 text-sm"><StarRating :model-value="order.ratings.merchant.score" readonly size="sm" /><b>Votre note</b></p>
            <p v-if="order.ratings.merchant.comment" class="text-sm text-slate-600">« {{ order.ratings.merchant.comment }} »</p>
          </template>
          <template v-else>
            <p class="font-bold">Notez le livreur</p>
            <StarRating :model-value="0" label="Note du livreur" @update:model-value="openRating" />
            <p class="text-[13px] text-slate-500">Touchez une étoile. Votre note reste anonyme pour le livreur.</p>
          </template>
        </div>
        <div v-if="order.ratings.recipient" :class="order.ratings.can_rate || order.ratings.merchant ? 'border-t border-slate-100 pt-3' : ''">
          <p class="text-[13px] font-extrabold uppercase tracking-wider text-slate-500">Note du client</p>
          <p class="mt-1 flex flex-wrap items-center gap-2 text-sm">
            <StarRating :model-value="order.ratings.recipient.score" readonly size="sm" /><b>{{ order.ratings.recipient.score }}/5</b>
            <span v-if="order.ratings.recipient.comment" class="text-slate-600">« {{ order.ratings.recipient.comment }} »</span>
          </p>
        </div>
      </section>

      <!-- Le client -->
      <section class="rounded-[22px] bg-white p-4 shadow-soft space-y-1">
        <div class="flex items-center justify-between">
          <p class="flex items-center gap-2 text-[15px] font-bold"><ColorIcon name="profil" :size="30" />Client</p>
          <button v-if="canEdit" class="text-sm font-bold text-[var(--app-color)]" @click="openEdit()">Modifier</button>
        </div>
        <p class="text-lg font-bold">{{ order.recipient.name || localPhone(order.recipient.phone) }}</p>
        <div class="flex flex-wrap gap-x-4 text-sm">
          <a :href="telLink(order.recipient.phone)" class="font-semibold text-[var(--app-color)]">{{ localPhone(order.recipient.phone) }}</a>
          <a v-if="order.recipient.phone2" :href="telLink(order.recipient.phone2)" class="text-[var(--app-color)]">{{ localPhone(order.recipient.phone2) }}</a>
        </div>
        <p class="pt-1 text-sm">{{ order.delivery.zone_name }}<span v-if="order.delivery.address"> · {{ order.delivery.address }}</span></p>
        <p v-if="order.delivery.landmark" class="text-sm text-slate-500">Repère : {{ order.delivery.landmark }}</p>
      </section>

      <!-- Articles -->
      <section v-if="order.items?.length || order.from_warehouse" class="rounded-[22px] bg-white p-4 shadow-soft space-y-1 text-sm">
        <p class="text-slate-500">{{ order.from_warehouse ? `Préparée à ${order.pickup.hub_name}` : 'Articles' }}</p>
        <p v-for="item in order.items" :key="item.id">
          <strong>{{ item.quantity }} ×</strong> {{ item.label }}
          <span v-if="item.stock_state === 'reserved'" class="text-xs text-amber-700">· réservé</span>
          <span v-else-if="item.stock_state === 'released'" class="text-xs text-slate-500">· remis en stock</span>
        </p>
      </section>

      <!-- Frais facturés au marchand -->
      <section v-if="order.expenses?.length" class="rounded-[22px] bg-white p-4 shadow-soft space-y-2 text-sm">
        <h2 class="font-bold">Frais de la course</h2>
        <div v-for="e in order.expenses" :key="e.id" :class="['flex justify-between gap-3', e.cancelled_at ? 'line-through text-slate-400' : '']">
          <span class="text-slate-600">{{ e.description }}</span><span class="whitespace-nowrap">− {{ money(e.amount) }}</span>
        </div>
        <p class="text-slate-500 text-xs">Déduits de votre point.</p>
      </section>

      <!-- Expédition -->
      <section v-if="order.is_shipping && order.shipping.carrier" class="rounded-[22px] bg-white p-4 shadow-soft space-y-1 text-sm">
        <h2 class="font-bold">Colis expédié</h2>
        <p>Par <strong>{{ order.shipping.carrier }}</strong><span v-if="order.shipping.reference"> · ticket {{ order.shipping.reference }}</span></p>
        <p class="text-slate-500">Frais d'expédition de {{ money(order.shipping.fee) }} déduits de votre point.</p>
      </section>

      <!-- L'histoire du colis -->
      <section class="rounded-[22px] bg-white p-4 shadow-soft">
        <MerchantHistory v-if="!fullHistory" :events="[...order.events].reverse()" :next="nextStep" />
        <OrderTimeline v-else :events="[...order.events].reverse()" />
        <button class="mt-3 text-sm font-bold text-[var(--app-color)]" @click="fullHistory = !fullHistory">{{ fullHistory ? 'Voir l\'essentiel' : 'Voir le détail complet' }}</button>
      </section>
    </div>

    <!-- Actions fréquentes, au pouce -->
    <div class="fixed bottom-0 inset-x-0 z-20 bg-white border-t border-slate-200 pb-safe">
      <div class="mx-auto max-w-lg px-3 py-2.5 flex gap-2">
        <a :href="telLink(order.recipient.phone)" class="m-btn-primary !py-3 !px-2 !text-sm flex-1"><AppIcon name="phone" class="h-[18px] w-[18px]" />Client</a>
        <a v-if="courier" :href="telLink(courier.phone)" class="m-btn-secondary !py-3 !px-2 !text-sm flex-1"><AppIcon name="moto" class="h-[18px] w-[18px]" />{{ courier.name.split(' ')[0] }}</a>
        <a v-if="!isFinal" :href="whatsappShare" target="_blank" rel="noopener" class="m-btn-secondary !py-3 !px-2 !text-sm flex-1"><AppIcon name="chat" class="h-[18px] w-[18px]" />Suivi</a>
        <button v-else class="m-btn-secondary !py-3 !px-2 !text-sm flex-1" @click="copy(trackingUrl)"><AppIcon name="link" class="h-[18px] w-[18px]" />Lien</button>
        <button class="m-btn-secondary !py-3 !px-0 !w-12 shrink-0" aria-label="Autres actions" @click="sheet = 'more'"><AppIcon name="dots" class="h-5 w-5" :stroke="3" /></button>
      </div>
    </div>

    <!-- Autres actions -->
    <BottomSheet :open="sheet === 'more'" title="Autres actions" @close="sheet = null">
      <div class="space-y-2">
        <button v-if="!isFinal" class="m-btn-secondary !justify-start" @click="sheet = 'note'"><AppIcon name="chat" class="h-5 w-5" />Écrire au livreur ou à l'agence</button>
        <button v-if="canEdit" class="m-btn-secondary !justify-start" @click="openEdit()"><AppIcon name="edit" class="h-5 w-5" />Modifier le numéro ou l'adresse</button>
        <button v-if="order.needs_decision || canChangeDecision" class="m-btn-secondary !justify-start" @click="openDecision()"><AppIcon name="refresh" class="h-5 w-5" />Décider de la suite</button>
        <button v-else-if="!isFinal && !beforePickup && !order.return_requested" class="m-btn-secondary !justify-start" @click="openDecision('return')"><AppIcon name="back" class="h-5 w-5" />Demander le retour du colis</button>
        <button class="m-btn-secondary !justify-start" @click="copy(trackingUrl)"><AppIcon name="link" class="h-5 w-5" />Copier le lien de suivi</button>
        <button class="m-btn-secondary !justify-start" @click="copy(order.tracking_code)"><AppIcon name="copy" class="h-5 w-5" />Copier le code {{ order.tracking_code }}</button>
        <button v-if="beforePickup" class="m-btn-secondary !justify-start text-red-600" @click="sheet = 'cancel'"><AppIcon name="x" class="h-5 w-5" />Annuler la course</button>
      </div>
    </BottomSheet>

    <!-- Décider après un échec : le motif, les quatre choix, le client prévenu -->
    <BottomSheet :open="sheet === 'decide'" title="Que faire de ce colis ?" @close="sheet = null">
      <div class="space-y-2">
        <p v-if="failureLine" class="flex gap-2 rounded-xl bg-orange-50 p-3 text-sm"><AppIcon name="alert" class="h-[18px] w-[18px] shrink-0 text-orange-700 mt-px" /><span>{{ failureLine }}</span></p>
        <button
          v-for="c in choices"
          :key="c.value"
          type="button"
          :class="['tap w-full flex items-center gap-3 rounded-2xl p-3 text-left ring-1', choice === c.value ? 'ring-2 ring-[var(--app-color)] bg-emerald-50' : 'ring-slate-200 bg-white']"
          @click="choose(c.value)"
        >
          <span :class="['h-9 w-9 shrink-0 rounded-xl grid place-items-center', choice === c.value ? 'bg-[var(--app-color)] text-white' : 'bg-slate-100 text-slate-700']"><AppIcon :name="c.icon" class="h-[19px] w-[19px]" /></span>
          <span class="min-w-0 flex-1"><b class="block">{{ c.label }}</b><span class="text-[13px] text-slate-500">{{ c.text }}</span></span>
          <AppIcon v-if="choice === c.value" name="check" class="h-5 w-5 text-[var(--app-color)]" />
        </button>

        <div v-if="choice === 'other'" class="space-y-2 pt-1">
          <ChoiceChips v-model="when" :options="deliveryOptions" :columns="3" label="Jour de la nouvelle livraison" />
          <p v-if="closed" class="text-xs text-slate-500">{{ closedReason }}.</p>
          <input v-if="when === 'other'" v-model="reschedule.delivery_scheduled_date" type="date" :min="minDate" class="m-input" aria-label="Jour de la nouvelle livraison">
        </div>

        <textarea v-if="choice !== 'fix'" v-model="reschedule.note" rows="2" class="m-input" :placeholder="choice === 'return' ? 'Raison (facultatif)' : 'Message au livreur (facultatif) : ex. le client sera là après 17 h'" />

        <!-- Client prévenu sur WhatsApp, avec le message visible avant l'envoi -->
        <label v-if="choice === 'tomorrow' || choice === 'other'" class="block rounded-2xl border border-dashed border-slate-300 p-3 text-sm">
          <span class="flex items-center gap-2 font-bold"><input v-model="notifyClient" type="checkbox" class="h-5 w-5 accent-[var(--app-color)]">Prévenir le client sur WhatsApp</span>
          <span v-if="notifyClient" class="mt-2 block rounded-xl bg-[#dcf8c6] px-3 py-2 text-slate-900">{{ clientMessage }}</span>
        </label>

        <button class="m-btn-primary" :disabled="busy || !canConfirm" @click="confirmDecision">{{ confirmLabel }}</button>
      </div>
    </BottomSheet>

    <BottomSheet :open="sheet === 'rate'" :title="`Noter ${courierFirst}`" @close="sheet = null">
      <div class="space-y-4">
        <div>
          <StarRating v-model="rating.score" label="Note du livreur" />
          <p class="mt-1 h-5 text-sm font-semibold text-amber-700">{{ SCORE_WORDS[rating.score] || '' }}</p>
        </div>
        <div>
          <p class="m-label">Points forts</p>
          <div class="flex flex-wrap gap-2">
            <button v-for="t in MERCHANT_TAGS.filter((x) => x.good)" :key="t.code" type="button" :aria-pressed="rating.tags.includes(t.code)" :class="tagClass(t)" @click="toggleTag(t.code)">{{ t.label }}</button>
          </div>
        </div>
        <div>
          <p class="m-label">À améliorer</p>
          <div class="flex flex-wrap gap-2">
            <button v-for="t in MERCHANT_TAGS.filter((x) => !x.good)" :key="t.code" type="button" :aria-pressed="rating.tags.includes(t.code)" :class="tagClass(t)" @click="toggleTag(t.code)">{{ t.label }}</button>
          </div>
        </div>
        <textarea v-model="rating.comment" rows="2" maxlength="500" class="m-input" placeholder="Un commentaire (facultatif)…" aria-label="Commentaire" />
        <button class="m-btn-primary" :disabled="busy || !rating.score" @click="doRate">Enregistrer la note</button>
      </div>
    </BottomSheet>

    <BottomSheet :open="sheet === 'cancel'" title="Annuler la course ?" @close="sheet = null">
      <div class="space-y-3">
        <textarea v-model="noteText" rows="2" class="m-input" placeholder="Raison (facultatif)" />
        <button class="m-btn-danger" :disabled="busy" @click="doCancel">Oui, annuler la course</button>
        <button class="m-btn-secondary" @click="sheet = null">Non, garder</button>
      </div>
    </BottomSheet>

    <BottomSheet :open="sheet === 'note'" title="Envoyer un message" @close="sheet = null">
      <div class="space-y-3">
        <textarea v-model="noteText" rows="3" class="m-input" placeholder="Ex. : le client préfère être livré au bureau" />
        <button class="m-btn-primary" :disabled="busy || !noteText.trim()" @click="doNote">Envoyer</button>
      </div>
    </BottomSheet>

    <BottomSheet :open="sheet === 'edit'" title="Modifier la livraison" @close="sheet = null">
      <div class="space-y-3">
        <div><label class="m-label">Téléphone</label><input v-model="edit.recipient_phone" class="m-input" inputmode="tel"></div>
        <div><label class="m-label">Autre numéro</label><input v-model="edit.recipient_phone2" class="m-input" inputmode="tel"></div>
        <div>
          <label class="m-label">Commune / quartier</label>
          <select v-model="edit.delivery_zone_id" class="m-input">
            <option v-for="z in zones" :key="z.id" :value="z.id">{{ z.full_name }}</option>
          </select>
        </div>
        <div><label class="m-label">Adresse</label><input v-model="edit.delivery_address" class="m-input"></div>
        <div><label class="m-label">Repère</label><input v-model="edit.delivery_landmark" class="m-input"></div>
        <button class="m-btn-primary" :disabled="busy" @click="doEdit">{{ relivrer ? 'Enregistrer puis choisir le jour' : 'Enregistrer' }}</button>
      </div>
    </BottomSheet>
  </div>
  <p v-else class="text-center text-slate-400 py-10">{{ error || 'Chargement…' }}</p>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import OrderTimeline from '../../components/OrderTimeline.vue'
import MerchantHistory from '../../components/mobile/MerchantHistory.vue'
import AppIcon from '../../components/mobile/AppIcon.vue'
import ColorIcon from '../../components/mobile/ColorIcon.vue'
import BottomSheet from '../../components/mobile/BottomSheet.vue'
import ChoiceChips from '../../components/mobile/ChoiceChips.vue'
import StarRating from '../../components/ratings/StarRating.vue'
import { MERCHANT_TAGS, SCORE_WORDS } from '../../components/ratings/ratingWords'
import { GROUPS, STAGE_ICONS, orderStage } from '../../utils/orderStage'
import { isoDay, longDay, useDeliveryDate } from '../../composables/useDeliveryDate'
import { pageTitle } from '../../composables/usePageTitle'
import { useAuthStore } from '../../stores/auth'
import { useToastStore } from '../../stores/toasts'
import { orderChanges, lastOrderChange } from '../../composables/useRealtime'
import { localPhone, money, telLink, today } from '../../utils/format'
import { BEFORE_PICKUP, FINAL } from '../../utils/workflow'
import { siteOrigin } from '../../native'

const route = useRoute()
const auth = useAuthStore()
const toasts = useToastStore()

const order = ref(null)
const error = ref('')
const sheet = ref(null)
const busy = ref(false)
const noteText = ref('')
const zones = ref([])
const edit = reactive({})
const reschedule = reactive({ delivery_scheduled_date: null, note: '' })
// Jours de relivraison : mêmes règles que la nouvelle course (« Aujourd'hui » grisé si la journée est fermée)
const { day, when, options: deliveryOptions, closed, closedReason, minDate, isChosen: dateChosen, load: loadDeliveryDay, reset: resetDate } = useDeliveryDate(reschedule)
// Après « Corriger », le choix du jour suit l'enregistrement
const relivrer = ref(false)
const fullHistory = ref(false)

const isFinal = computed(() => FINAL.includes(order.value?.status))
const stage = computed(() => orderStage(order.value))
// Les 4 étapes du suivi
const STEP_LABELS = ['Créée', 'Récupérée', 'En route', 'Livrée']
const groupLabel = computed(() => GROUPS.find((g) => g.key === stage.value.group)?.label)
const shopName = computed(() => auth.user?.merchant?.business_name || '')

const dayWord = (value) => {
  const d = new Date(value)
  const diff = Math.round((new Date().setHours(0, 0, 0, 0) - new Date(d).setHours(0, 0, 0, 0)) / 86400000)
  return diff === 0 ? 'aujourd\'hui' : diff === 1 ? 'hier' : `le ${d.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' })}`
}
const hour = (value) => new Date(value).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
const recipientName = computed(() => order.value.recipient.name || localPhone(order.value.recipient.phone))
const firstName = (person) => (person?.name || '').split(' ')[0]

// Phrase en tête : qui fait quoi, pour qui
const headline = computed(() => {
  const o = order.value
  if (o.status === 'delivered') return o.is_shipping ? `Expédiée pour ${recipientName.value}` : `Livrée à ${recipientName.value}`
  if (o.status === 'out_for_delivery') return o.delivery_courier ? `${firstName(o.delivery_courier)} apporte le colis à ${recipientName.value}` : `En route vers ${recipientName.value}`
  if (['pickup_assigned', 'pickup_in_progress'].includes(o.status) && o.pickup_courier) return `${firstName(o.pickup_courier)} vient chercher le colis chez vous`
  return stage.value.text
})
const subline = computed(() => {
  const o = order.value
  if (o.status === 'delivered' && o.delivered_at) return `${dayWord(o.delivered_at)} à ${hour(o.delivered_at)}${o.delivery_courier ? ` par ${o.delivery_courier.name}` : ''}`.replace(/^./, (c) => c.toUpperCase())
  return [o.delivery.zone_name, o.tracking_code].filter(Boolean).join(' · ')
})
const nextStep = computed(() => {
  if (isFinal.value || order.value.needs_decision) return ''
  return order.value.is_shipping ? 'Expédiée' : 'Livrée au client'
})

// Livraison prévue un autre jour
const plannedDay = computed(() => {
  const d = order.value.delivery.scheduled_date
  return d && !isFinal.value && order.value.status !== 'rescheduled' && d > today() ? longDay(d) : null
})

// Dernier échec : motif, livreur, heure et tentative
const failureLine = computed(() => {
  const o = order.value
  const failed = [...(o.events || [])].reverse().find((e) => e.to_status === 'delivery_failed' || e.type === 'incident')
  const reason = failed?.incident_reason?.label || o.last_incident?.label
  if (!reason && !failed) return ''
  return [
    reason || 'Livraison échouée',
    failed?.actor_name && failed.actor_role === 'courier' ? `${failed.actor_name} à ${hour(failed.created_at)}` : failed ? `à ${hour(failed.created_at)}` : null,
    o.max_attempts ? `tentative ${o.attempts_count || 1} sur ${o.max_attempts}` : null,
    failed?.note,
  ].filter(Boolean).join(' · ')
})

// Le livreur à joindre : celui de la livraison, sinon celui du ramassage, tant que la course est en cours
const courier = computed(() => (isFinal.value ? null : order.value.delivery_courier || order.value.pickup_courier || null))

// Ce qui revient à l'e-commerçant : encaissé − livraison − expédition − autres frais
const collected = computed(() => order.value.amounts.collected_amount)
const expensesTotal = computed(() => (order.value.expenses || []).filter((e) => !e.cancelled_at).reduce((sum, e) => sum + e.amount, 0))
const shippingFee = computed(() => (order.value.is_shipping ? (order.value.shipping.fee ?? order.value.shipping.fee_estimate ?? 0) : 0))
const net = computed(() => (collected.value ?? order.value.amounts.cod_amount) - order.value.amounts.total_fees - shippingFee.value - expensesTotal.value)
const showNet = computed(() => order.value.status === 'delivered' || !isFinal.value)
const trackingUrl = computed(() => `${siteOrigin()}/suivi/${order.value.tracking_code}`)
const beforePickup = computed(() => BEFORE_PICKUP.includes(order.value?.status))
const canEdit = computed(() => !isFinal.value && order.value?.status !== 'out_for_delivery')
const canChangeDecision = computed(() => !!order.value.decision_editable)

const whatsappShare = computed(() => {
  const o = order.value
  const text = `Bonjour, votre commande ${shopName.value} sera livrée bientôt.\nSuivi : ${trackingUrl.value}\nCode de livraison à donner au livreur : ${o.delivery_code}`
  return `https://wa.me/${o.recipient.phone.replace(/\D/g, '')}?text=${encodeURIComponent(text)}`
})

/* ---------- Décision ---------- */
const choice = ref('tomorrow')
const notifyClient = ref(true)
const tomorrowLabel = computed(() => deliveryOptions.value.find((o) => o.value === 'tomorrow')?.description || '')
const choices = computed(() => [
  ...(order.value.needs_decision || canChangeDecision.value ? [
    { value: 'tomorrow', icon: 'refresh', label: 'Relivrer demain', text: `${tomorrowLabel.value} · le plus courant` },
    { value: 'other', icon: 'calendar', label: 'Choisir un autre jour', text: 'Le client a donné une date' },
    { value: 'fix', icon: 'edit', label: 'Corriger le numéro ou l\'adresse', text: 'Puis relivrer' },
  ] : []),
  ...(order.value.return_requested ? [] : [{ value: 'return', icon: 'back', label: 'Faire revenir le colis', text: 'Des frais de retour peuvent s\'appliquer' }]),
])

function choose(value) {
  choice.value = value
  if (value === 'tomorrow') {
    when.value = 'tomorrow'
    reschedule.delivery_scheduled_date = isoDay(1, day.value?.today || isoDay(0))
  }
  if (value === 'other') resetDate()
}

function openDecision(preset = 'tomorrow') {
  resetDate()
  reschedule.note = ''
  notifyClient.value = true
  loadDeliveryDay()
  choose(choices.value.some((c) => c.value === preset) ? preset : choices.value[0]?.value)
  sheet.value = 'decide'
}

const clientMessage = computed(() => {
  const d = reschedule.delivery_scheduled_date
  const whenText = d ? (choice.value === 'tomorrow' ? 'demain' : `le ${longDay(d)}`) : 'bientôt'
  return `Bonjour, nous n'avons pas pu vous livrer. Votre commande ${shopName.value} sera livrée ${whenText}. Merci de rester joignable.`
})

const canConfirm = computed(() => {
  if (choice.value === 'tomorrow' || choice.value === 'other') return dateChosen.value
  return !!choice.value
})
const confirmLabel = computed(() => ({
  tomorrow: 'Confirmer : relivrer demain',
  other: 'Confirmer la relivraison',
  fix: 'Corriger le numéro ou l\'adresse',
  return: 'Demander le retour',
}[choice.value] || 'Confirmer'))

async function confirmDecision() {
  if (choice.value === 'fix') {
    openEdit(true)
    return
  }
  if (choice.value === 'return') {
    noteText.value = reschedule.note
    await doReturn()
    return
  }
  const ok = await run(() => http.post(`/orders/${order.value.id}/status`, {
    status: 'rescheduled', rescheduled_to: reschedule.delivery_scheduled_date, note: reschedule.note || undefined,
  }), 'Relivraison prévue.')
  if (ok && notifyClient.value) {
    const url = `https://wa.me/${order.value.recipient.phone.replace(/\D/g, '')}?text=${encodeURIComponent(clientMessage.value)}`
    if (!window.open(url, '_blank')) window.location.href = url
  }
}

async function load() {
  try {
    order.value = (await http.get(`/orders/${route.params.id}`)).data.data
    pageTitle.value = order.value.tracking_code
    // Décision demandée depuis l'accueil ou la liste
    if (['decider', 'reporter'].includes(route.query.action) && (order.value.needs_decision || order.value.decision_editable) && !sheet.value) {
      openDecision('tomorrow')
    }
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

async function run(action, success) {
  busy.value = true
  try {
    const { data } = await action()
    if (data?.data?.id) order.value = data.data
    else await load()
    sheet.value = null
    noteText.value = ''
    toasts.success(success)
    return true
  } catch (e) {
    toasts.error(apiErrorMessage(e))
    return false
  } finally {
    busy.value = false
  }
}

const doReturn = () => run(() => http.post(`/orders/${order.value.id}/return-request`, { note: noteText.value || undefined }), 'Retour demandé.')
const doCancel = () => run(() => http.post(`/orders/${order.value.id}/status`, { status: 'cancelled', cancel_reason: noteText.value || undefined }), 'Course annulée.')
const doNote = () => run(() => http.post(`/orders/${order.value.id}/notes`, { note: noteText.value }), 'Message envoyé.')

// Noter le livreur : une étoile touchée ouvre le panneau
const rating = reactive({ score: 0, tags: [], comment: '' })
const courierName = computed(() => order.value?.delivery_courier?.name || '')
const courierFirst = computed(() => courierName.value.split(' ')[0] || 'le livreur')
function openRating(score) {
  Object.assign(rating, { score, tags: [], comment: '' })
  sheet.value = 'rate'
}
function toggleTag(code) {
  rating.tags = rating.tags.includes(code) ? rating.tags.filter((c) => c !== code) : [...rating.tags, code]
}
const tagClass = (t) => ['tap rounded-full border-[1.5px] px-3 py-1.5 text-sm font-semibold', rating.tags.includes(t.code)
  ? (t.good ? 'border-emerald-600 bg-emerald-50 text-emerald-800' : 'border-red-400 bg-red-50 text-red-800')
  : 'border-slate-300 bg-white text-slate-700']
const doRate = () => run(() => http.post(`/orders/${order.value.id}/rating`, { score: rating.score, tags: rating.tags, comment: rating.comment || null }), 'Merci pour votre note.')

async function openEdit(thenRedeliver = false) {
  relivrer.value = thenRedeliver === true
  if (!zones.value.length) zones.value = (await http.get('/zones')).data.data
  Object.assign(edit, {
    recipient_phone: order.value.recipient.phone,
    recipient_phone2: order.value.recipient.phone2 || '',
    delivery_zone_id: order.value.delivery.zone_id,
    delivery_address: order.value.delivery.address || '',
    delivery_landmark: order.value.delivery.landmark || '',
  })
  sheet.value = 'edit'
}

async function doEdit() {
  const ok = await run(
    () => http.patch(`/orders/${order.value.id}`, Object.fromEntries(Object.entries(edit).filter(([, v]) => v !== ''))),
    'Livraison modifiée.',
  )
  // Adresse corrigée : reste à choisir le jour de la nouvelle livraison
  if (ok && relivrer.value && (order.value.needs_decision || order.value.decision_editable)) openDecision('tomorrow')
  relivrer.value = false
}

async function copy(text) {
  try {
    await navigator.clipboard.writeText(text)
    toasts.success('Copié.')
  } catch {
    toasts.push(text)
  }
  if (sheet.value === 'more') sheet.value = null
}

watch(orderChanges, () => {
  if (lastOrderChange.value?.order?.id === order.value?.id) load()
})
// Jour « demain » connu dès que l'agence répond (fermeture du jour comprise)
watch(day, () => { if (sheet.value === 'decide' && choice.value === 'tomorrow') choose('tomorrow') })
onMounted(load)
</script>
