<template>
  <div class="space-y-4 pb-10">
    <div class="flex rounded-xl bg-slate-200 p-1">
      <button v-for="t in tabs" :key="t.value" :class="['tap flex-1 rounded-lg py-2.5 text-sm font-semibold', tab === t.value ? 'bg-white shadow-sm text-[var(--app-color)]' : 'text-slate-600']" @click="tab = t.value">
        {{ t.label }}
        <span :class="['ml-1 rounded-full px-1.5 text-xs', parcels(t.value) ? 'bg-[var(--app-color)] text-white' : 'bg-slate-300 text-slate-600']">{{ parcels(t.value) }}</span>
      </button>
    </div>

    <p v-if="loading" class="text-center text-slate-400 py-10">Chargement…</p>
    <p v-else-if="error" class="text-center text-red-600 py-8">{{ error }}</p>
    <div v-else-if="!groups.length" class="text-center text-slate-500 py-12 space-y-1">
      <p class="text-4xl">🤝</p>
      <p>{{ tab === 'receive' ? 'Aucun colis à recevoir d\'un autre livreur.' : 'Aucun colis à remettre à un autre livreur.' }}</p>
    </div>

    <!-- À recevoir : saisir le code montré par l'autre livreur, cocher les colis reçus -->
    <template v-if="tab === 'receive'">
      <section v-for="g in groups" :key="g.courier_id" class="m-card p-4 space-y-3">
        <div>
          <p class="text-xs font-semibold uppercase tracking-wide text-[var(--app-color)]">À recevoir de</p>
          <p class="text-lg font-bold">{{ g.name }}</p>
          <p class="text-sm text-slate-500">{{ g.parcels.length }} colis · {{ g.relay ? 'code prêt chez lui' : 'demandez-lui d\'afficher le code' }}</p>
        </div>
        <ContactButtons :courier="g" />

        <div class="divide-y rounded-xl border">
          <label v-for="o in g.parcels" :key="o.id" class="flex items-center gap-3 p-3">
            <input v-model="checked[g.courier_id]" type="checkbox" :value="o.id" class="h-5 w-5">
            <span class="flex-1 min-w-0">
              <span class="block font-medium truncate">{{ o.recipient_name || o.tracking_code }}</span>
              <span class="block text-xs text-slate-500">{{ o.tracking_code }} · {{ o.is_return ? `↩️ retour à ${o.merchant}` : `${o.zone} · ${money(o.cod_amount)}` }}</span>
            </span>
          </label>
        </div>
        <p v-if="(checked[g.courier_id] || []).length < g.parcels.length" class="text-sm text-amber-700">Les colis non cochés restent chez {{ g.name }} : l'agence est prévenue.</p>

        <template v-if="g.relay">
          <label class="m-label" :for="`code-${g.courier_id}`">Code à 4 chiffres affiché par {{ g.name }}</label>
          <input :id="`code-${g.courier_id}`" v-model="codes[g.courier_id]" class="m-input text-center text-3xl font-bold tracking-[0.5em]" inputmode="numeric" maxlength="4" autocomplete="one-time-code" placeholder="••••">
          <p v-if="errors[g.courier_id]" class="text-sm text-red-600">{{ errors[g.courier_id] }}</p>
          <button class="m-btn-primary" :disabled="busy || (codes[g.courier_id] || '').length !== 4 || !(checked[g.courier_id] || []).length" @click="confirm(g)">
            ✅ J'ai reçu {{ (checked[g.courier_id] || []).length }} colis
          </button>
        </template>
        <p v-else class="rounded-xl bg-slate-50 p-3 text-sm text-slate-600">⏳ Quand {{ g.name }} affichera le code sur son téléphone, la saisie apparaîtra ici.</p>
      </section>
    </template>

    <!-- À remettre : choisir les colis et afficher le code à montrer -->
    <template v-else>
      <section v-for="g in groups" :key="g.courier_id" class="m-card p-4 space-y-3">
        <div>
          <p class="text-xs font-semibold uppercase tracking-wide text-[var(--app-color)]">À remettre à</p>
          <p class="text-lg font-bold">{{ g.name }}</p>
          <p class="text-sm text-slate-500">{{ g.parcels.length }} colis : c'est ce livreur qui part avec</p>
        </div>
        <ContactButtons :courier="g" />

        <div v-if="g.relay" class="rounded-2xl bg-[var(--app-color)] p-5 text-center text-white space-y-1">
          <p class="text-sm text-white/80">Montrez ce code à {{ g.name }}</p>
          <p class="text-5xl font-bold tracking-[0.3em]">{{ g.relay.code }}</p>
          <p class="text-xs text-white/80">{{ g.relay.order_ids.length }} colis · valable jusqu'à {{ time(g.relay.expires_at) }}</p>
        </div>

        <div class="divide-y rounded-xl border">
          <label v-for="o in g.parcels" :key="o.id" class="flex items-center gap-3 p-3">
            <input v-model="checked[g.courier_id]" type="checkbox" :value="o.id" class="h-5 w-5">
            <span class="flex-1 min-w-0">
              <span class="block font-medium truncate">{{ o.recipient_name || o.tracking_code }}</span>
              <span class="block text-xs text-slate-500">{{ o.tracking_code }} · {{ o.is_return ? `↩️ retour à ${o.merchant}` : `${o.merchant} · ${o.zone}` }}</span>
            </span>
          </label>
        </div>
        <p v-if="errors[g.courier_id]" class="text-sm text-red-600">{{ errors[g.courier_id] }}</p>
        <button :class="g.relay ? 'm-btn-secondary' : 'm-btn-primary'" :disabled="busy || !(checked[g.courier_id] || []).length" @click="offer(g)">
          {{ g.relay ? '🔄 Nouveau code' : `🔢 Afficher le code (${(checked[g.courier_id] || []).length} colis)` }}
        </button>
      </section>
    </template>
  </div>
</template>

<script setup>
import { computed, defineComponent, h, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { currentPosition } from '../../composables/useGeolocation'
import { useToastStore } from '../../stores/toasts'
import { mapsLink, money, telLink, whatsappLink } from '../../utils/format'

/**
 * Relais entre livreurs : celui qui a les colis affiche un code à 4 chiffres, celui qui doit les livrer le saisit et
 * coche les colis reçus. Obligatoire avant « Je pars livrer » quand le colis est chez un autre livreur.
 */
const ContactButtons = defineComponent({
  props: { courier: { type: Object, required: true } },
  setup(props) {
    const btn = 'tap rounded-2xl bg-slate-100 py-3 flex flex-col items-center gap-1 text-sm font-medium active:bg-slate-200'
    return () => h('div', { class: 'grid grid-cols-3 gap-2' }, [
      h('a', { href: telLink(props.courier.phone), class: btn }, [h('span', { class: 'text-2xl' }, '📞'), 'Appeler']),
      h('a', { href: whatsappLink(props.courier.phone), target: '_blank', class: btn }, [h('span', { class: 'text-2xl' }, '💬'), 'WhatsApp']),
      props.courier.lat
        ? h('a', { href: mapsLink(props.courier.lat, props.courier.lng, ''), target: '_blank', class: btn }, [h('span', { class: 'text-2xl' }, '🗺️'), 'Le rejoindre'])
        : h('span', { class: `${btn} opacity-40` }, [h('span', { class: 'text-2xl' }, '🗺️'), 'Position ?']),
    ])
  },
})

const toasts = useToastStore()
const board = ref({ give: [], receive: [] })
const tab = ref('receive')
const loading = ref(true)
const error = ref('')
const busy = ref(false)
const checked = reactive({})
const codes = reactive({})
const errors = reactive({})
const tabs = [{ value: 'receive', label: 'À recevoir' }, { value: 'give', label: 'À remettre' }]

const groups = computed(() => board.value[tab.value] || [])
const parcels = (t) => (board.value[t] || []).reduce((n, g) => n + g.parcels.length, 0)
const time = (v) => new Date(v).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })

async function load(first = false) {
  try {
    board.value = (await http.get('/courier/relays')).data.data
    // Tous les colis cochés par défaut (on garde les choix déjà faits)
    for (const g of [...board.value.give, ...board.value.receive]) {
      const ids = g.parcels.map((o) => o.id)
      checked[g.courier_id] = checked[g.courier_id]?.filter((id) => ids.includes(id)) ?? ids
      if (!checked[g.courier_id].length) checked[g.courier_id] = ids
    }
    if (first && !parcels('receive') && parcels('give')) tab.value = 'give'
    error.value = ''
  } catch (e) {
    error.value = apiErrorMessage(e, 'Impossible de charger les relais.')
  } finally {
    loading.value = false
  }
}

async function offer(g) {
  busy.value = true
  errors[g.courier_id] = ''
  try {
    const position = await currentPosition(3000).catch(() => null)
    await http.post('/courier/relays', { to_courier_id: g.courier_id, order_ids: checked[g.courier_id], ...(position || {}) })
    await load()
  } catch (e) {
    errors[g.courier_id] = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}

async function confirm(g) {
  busy.value = true
  errors[g.courier_id] = ''
  try {
    const position = await currentPosition(3000).catch(() => null)
    const { data } = await http.post(`/courier/relays/${g.relay.id}/confirm`, { code: codes[g.courier_id], order_ids: checked[g.courier_id], ...(position || {}) })
    toasts.success(`🤝 ${data.data.received} colis reçus de ${g.name}. Vous pouvez partir livrer.`)
    codes[g.courier_id] = ''
    await load()
  } catch (e) {
    errors[g.courier_id] = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}

// L'autre livreur avance de son côté (code affiché, colis reçus) : écran mis à jour tout seul
let timer = null
onMounted(() => {
  load(true)
  timer = setInterval(() => !document.hidden && !busy.value && load(), 8000)
})
onBeforeUnmount(() => clearInterval(timer))
</script>
