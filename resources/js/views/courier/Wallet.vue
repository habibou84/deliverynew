<template>
  <div v-if="wallet" class="space-y-4">
    <p v-if="stale" class="rounded-xl bg-amber-50 text-amber-800 text-sm px-4 py-3">Hors ligne : montants de la dernière connexion.</p>
    <section class="rounded-2xl p-5 text-white" :style="{ backgroundColor: 'var(--app-color)' }">
      <p class="text-sm opacity-80">{{ toRemit >= 0 ? 'À verser à la caisse' : 'La caisse vous doit' }}</p>
      <p class="text-4xl font-bold mt-1">{{ money(Math.abs(toRemit)) }}</p>
      <div v-if="wallet.balance && (wallet.balance.advances || wallet.balance.expenses || wallet.balance.pay_kept || wallet.pay_deduction)" class="mt-3 space-y-1 border-t border-white/25 pt-3 text-sm opacity-95">
        <div class="flex justify-between"><span>Argent encaissé</span><span>{{ money(wallet.balance.collected) }}</span></div>
        <div v-if="wallet.balance.advances" class="flex justify-between"><span>+ Avances de la caisse</span><span>{{ money(wallet.balance.advances) }}</span></div>
        <div v-if="wallet.pay_deduction" class="flex justify-between"><span>− Ma paie, déduite au versement</span><span>{{ money(wallet.pay_deduction) }}</span></div>
        <div v-if="wallet.balance.pay_kept" class="flex justify-between"><span>− Paie déjà gardée sur l'encaissé</span><span>{{ money(wallet.balance.pay_kept) }}</span></div>
        <div v-if="wallet.balance.expenses" class="flex justify-between"><span>− Frais payés pour les courses</span><span>{{ money(wallet.balance.expenses) }}</span></div>
      </div>
    </section>
    <RouterLink to="/livreur/gains" class="flex items-start gap-2 px-1 text-sm text-slate-600">
      <Icone nom="gain" taille="petite" class="mt-0.5 shrink-0" />
      <span>
        <template v-if="wallet.settles_on_remittance && wallet.pay_deduction">Votre entreprise déduit votre paie à chaque versement : gardez {{ money(wallet.pay_deduction) }}, versez le reste.</template>
        <template v-else-if="wallet.settles_on_remittance">Votre entreprise déduit votre paie à chaque versement à la caisse.</template>
        <template v-else>Votre paie est versée sur votre fiche de paie.</template>
        <span class="font-semibold text-[var(--app-color)]"> Voir Mes gains</span>
      </span>
    </RouterLink>

    <!-- Colis non reçus au dépôt : le livreur confirme ou conteste -->
    <section v-if="wallet.parcel_gaps?.length" class="space-y-2">
      <h2 class="flex items-center gap-2 font-semibold text-red-700"><Icone nom="urgent" />Colis non reçus au dépôt : à confirmer ({{ wallet.parcel_gaps.length }})</h2>
      <div v-for="g in wallet.parcel_gaps" :key="g.id" class="m-card p-4 space-y-3 border-l-4 border-red-500">
        <div>
          <p class="font-semibold">{{ g.tracking_code }} · {{ g.recipient_name }}</p>
          <p class="text-sm text-slate-600">{{ g.merchant }}</p>
          <p class="mt-1 text-sm">
            {{ g.source === 'drop_control' ? 'Absent du bac au contrôle' : 'Non reçu au point' }} par {{ g.reported_by || 'l\'agent' }}, {{ dateTime(g.created_at) }} :
            <strong>{{ g.reason_label }}</strong><template v-if="g.note"> « {{ g.note }} »</template>
          </p>
        </div>
        <p class="text-sm font-medium">Avez-vous encore ce colis ?</p>
        <div class="grid grid-cols-2 gap-2">
          <button class="m-btn-secondary" :disabled="answering" @click="answer(g, 'kept')">Oui, je l'ai encore</button>
          <button class="m-btn-primary" :disabled="answering" @click="answer(g, 'handed')">Non, je l'ai remis</button>
        </div>
      </div>
      <p class="text-sm text-slate-600 text-center">« Je l'ai remis » : un administrateur vérifie et tranche.</p>
    </section>

    <!-- Colis non livrés encore en main : à rapporter au dépôt lors du point -->
    <section v-if="parcelsToReturn.length || parcelsKept.length || onTheRoad.length || parcelsDropped.length" class="space-y-2">
      <p v-if="onTheRoad.length" class="flex items-start gap-2 rounded-xl bg-sky-50 text-sky-900 text-sm px-4 py-3">
        <Icone nom="livrer" taille="petite" class="mt-0.5" />{{ onTheRoad.length }} course{{ onTheRoad.length > 1 ? 's' : '' }} en chemin : indiquez « livré » ou « échec » avant de faire votre point à la caisse.
      </p>
      <template v-if="parcelsKept.length">
        <h2 class="flex items-center gap-2 font-semibold"><Icone nom="jour-ferme" />Colis à garder pour leur livraison ({{ parcelsKept.length }})</h2>
        <div class="m-card divide-y">
          <div v-for="p in parcelsKept" :key="p.id" class="p-4 flex justify-between gap-3">
            <div class="min-w-0">
              <p class="font-medium truncate">{{ p.recipient_name || p.recipient_phone }}</p>
              <p class="text-xs text-slate-600">{{ p.tracking_code }} · {{ p.merchant }}</p>
            </div>
            <p v-if="p.date_to_confirm" class="flex items-center justify-end gap-1 text-xs text-amber-700"><Icone nom="date" taille="petite" />Date à confirmer</p>
            <p v-else class="text-xs text-right text-indigo-700">Livraison {{ date(p.kept_for) }}</p>
          </div>
        </div>
        <p class="text-sm text-slate-600 text-center">Gardez-les avec vous : la livraison vous sera confiée le jour prévu.<template v-if="parcelsKept.some((p) => p.date_to_confirm)"> « Date à confirmer » : l'agence vous dira quand le livrer ou le rapporter.</template></p>
      </template>
      <template v-if="parcelsToReturn.length">
        <h2 class="flex items-center gap-2 font-semibold"><Icone nom="course" />Colis à rapporter au dépôt ({{ parcelsToReturn.length }})</h2>
        <div class="m-card divide-y">
          <div v-for="p in parcelsToReturn" :key="p.id" class="p-4 flex justify-between gap-3">
            <div class="min-w-0">
              <p class="font-medium truncate">{{ p.recipient_name || p.recipient_phone }}</p>
              <p class="text-xs text-slate-600">{{ p.tracking_code }} · {{ p.merchant }}</p>
            </div>
            <p class="text-xs text-right text-slate-600">{{ p.rescheduled_to ? `Reporté au ${date(p.rescheduled_to)}` : (p.incident || p.status_label) }}</p>
          </div>
        </div>
        <p class="text-sm text-slate-600 text-center">Remettez-les à la caisse lors de votre point, ou déposez-les vous-même dans le bac du dépôt.</p>
        <RouterLink to="/livreur/caisse/deposer" class="m-btn-primary inline-flex items-center justify-center gap-2"><Icone nom="scanner" />Déposer mes colis au dépôt</RouterLink>
      </template>
      <template v-if="parcelsDropped.length">
        <h2 class="flex items-center gap-2 font-semibold"><Icone nom="depot" />Déposés dans le bac, en attente du contrôle ({{ parcelsDropped.length }})</h2>
        <div class="m-card divide-y">
          <div v-for="p in parcelsDropped" :key="p.id" class="p-4 flex justify-between gap-3">
            <div class="min-w-0">
              <p class="font-medium truncate">{{ p.recipient_name || p.recipient_phone }}</p>
              <p class="text-xs text-slate-600">{{ p.tracking_code }} · {{ p.merchant }}</p>
            </div>
            <p class="text-xs text-right text-sky-700">À contrôler</p>
          </div>
        </div>
        <p class="text-sm text-slate-600 text-center">Un agent contrôle le bac ; sans contrôle, ils sont confirmés au bout de 24 h.</p>
      </template>
    </section>

    <section v-if="cashAdvances.length" class="space-y-2">
      <h2 class="font-semibold">Avances reçues de la caisse</h2>
      <div class="m-card divide-y">
        <div v-for="a in cashAdvances" :key="a.id" class="p-4 flex justify-between gap-3">
          <div class="min-w-0">
            <p class="font-medium truncate">{{ a.reason }}</p>
            <p class="text-xs text-slate-600">{{ dateTime(a.given_at) }}<span v-if="a.tracking_code"> · {{ a.tracking_code }}</span></p>
          </div>
          <p class="font-semibold whitespace-nowrap">+ {{ money(a.amount) }}</p>
        </div>
      </div>
    </section>

    <section v-if="wallet.expenses?.length" class="space-y-2">
      <h2 class="font-semibold">Frais que j'ai payés</h2>
      <div class="m-card divide-y">
        <div v-for="e in wallet.expenses" :key="e.id" class="p-4 flex justify-between gap-3">
          <div class="min-w-0">
            <p class="font-medium truncate">{{ e.description }}</p>
            <p class="text-xs text-slate-600">{{ e.tracking_code }} · {{ dateTime(e.created_at) }}</p>
          </div>
          <p class="font-semibold whitespace-nowrap text-emerald-700">− {{ money(e.amount) }}</p>
        </div>
      </div>
      <p class="text-sm text-slate-600 text-center">Déduits de ce que vous versez à la caisse.</p>
    </section>

    <section v-if="wallet.collections.length" class="space-y-2">
      <h2 class="font-semibold">Argent encaissé</h2>
      <div class="m-card divide-y">
        <div v-for="c in wallet.collections" :key="c.id" class="p-4 flex justify-between gap-3">
          <div class="min-w-0">
            <p class="font-medium truncate">{{ c.recipient_name || 'Client' }}</p>
            <p class="text-xs text-slate-600">{{ c.tracking_code }} · {{ c.method_label }} · {{ dateTime(c.collected_at) }}</p>
          </div>
          <p class="font-semibold whitespace-nowrap">{{ money(c.amount_collected) }}</p>
        </div>
      </div>
      <p class="text-sm text-slate-600 text-center">Remettez cet argent à la caisse de l'agence en fin de journée.</p>
    </section>

    <EmptyState v-if="!wallet.parcels?.length && !wallet.collections.length && !wallet.advances?.length && !wallet.expenses?.length" art="argent" title="Rien à verser" text="L'argent encaissé lors de vos livraisons apparaîtra ici." />
  </div>
  <div v-else-if="error" class="text-center py-10 space-y-3">
    <p class="text-slate-600">{{ error }}</p>
    <button class="m-btn m-btn-secondary" @click="load">Réessayer</button>
  </div>
  <p v-else class="text-center text-slate-600 py-10">Chargement…</p>
</template>

<script setup>
import Icone from '../../components/ui/Icone.vue'
import { computed, onMounted, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { useToastStore } from '../../stores/toasts'
import { cachedGet } from '../../composables/useCachedApi'
import EmptyState from '../../components/mobile/EmptyState.vue'
import { date, dateTime, money } from '../../utils/format'

const wallet = ref(null)
const stale = ref(false)
const error = ref('')
// À rendre au dépôt (ni gardés pour leur livraison, ni à rapporter au marchand, ni déjà déposés dans le bac)
const parcelsToReturn = computed(() => (wallet.value?.parcels || []).filter((p) => p.due))
const parcelsDropped = computed(() => (wallet.value?.parcels || []).filter((p) => p.dropped))
// Mission ramassage « garde » : colis à garder jusqu'à leur jour de livraison
const parcelsKept = computed(() => (wallet.value?.parcels || []).filter((p) => !p.on_the_road && p.kept_for && !p.dropped))
const onTheRoad = computed(() => (wallet.value?.parcels || []).filter((p) => p.on_the_road))
// Avances réelles de la caisse (la paie gardée sur l'encaissé figure dans le solde)
const cashAdvances = computed(() => (wallet.value?.advances || []).filter((a) => !a.pay_kept))
// Ce qu'il remet : son solde, moins la paie qu'il garde au versement (plan « paie déduite à chaque versement »)
const toRemit = computed(() => (wallet.value?.cash_in_hand || 0) - (wallet.value?.pay_deduction || 0))

async function load() {
  error.value = ''
  try {
    const { data, stale: offline } = await cachedGet('/courier/wallet')
    wallet.value = data.data
    stale.value = offline
  } catch (e) {
    error.value = apiErrorMessage(e, 'Impossible de charger votre caisse.')
  }
}

// Colis non reçu au dépôt : « je l'ai encore » ou « je l'ai remis »
const toasts = useToastStore()
const answering = ref(false)
async function answer(g, value) {
  answering.value = true
  try {
    await http.post(`/courier/parcel-gaps/${g.id}/answer`, { answer: value })
    toasts.success(value === 'kept' ? 'Merci : rapportez ce colis au dépôt.' : 'Contestation envoyée : un administrateur va vérifier.')
    await load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    answering.value = false
  }
}

onMounted(load)
</script>
