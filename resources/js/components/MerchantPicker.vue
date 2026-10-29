<template>
  <section class="card p-4 space-y-3">
    <!-- E-commerçant choisi -->
    <div v-if="selected" class="flex items-center justify-between gap-3">
      <div class="min-w-0">
        <p class="label mb-0">E-commerçant *</p>
        <p class="font-semibold truncate">{{ selected.business_name }}</p>
        <p class="text-sm text-gray-600">{{ phone(selected.phone) }}<span v-if="selected.pickup_zone"> · {{ selected.pickup_zone.name }}</span></p>
      </div>
      <button type="button" class="btn-secondary" @click="change">Changer</button>
    </div>

    <!-- Nouvel e-commerçant : créé avec la course -->
    <div v-else-if="creating" class="space-y-3">
      <div class="flex items-center justify-between gap-2">
        <p class="label mb-0">E-commerçant *</p>
        <button type="button" class="text-sm font-semibold text-emerald-700 px-2 py-1" @click="cancelNew">Rechercher plutôt</button>
      </div>
      <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 space-y-3">
        <p class="font-semibold text-emerald-900">Nouvel e-commerçant</p>
        <div class="grid sm:grid-cols-2 gap-3">
          <div>
            <label class="label" for="nm-name">Nom de la boutique *</label>
            <input id="nm-name" v-model="draft.business_name" class="input" required maxlength="255" @input="emitDraft">
            <p v-if="errors['new_merchant.business_name']" class="field-error">{{ errors['new_merchant.business_name'][0] }}</p>
          </div>
          <div>
            <label class="label" for="nm-phone">Téléphone WhatsApp *</label>
            <input id="nm-phone" v-model="draft.phone" class="input" required inputmode="tel" placeholder="07 00 00 00 00" autocomplete="off" @input="emitDraft(); checkPhone()">
            <p v-if="errors['new_merchant.phone']" class="field-error">{{ errors['new_merchant.phone'][0] }}</p>
          </div>
          <div>
            <label class="label" for="nm-contact">Nom du responsable</label>
            <input id="nm-contact" v-model="draft.contact_name" class="input" placeholder="Facultatif" maxlength="255" @input="emitDraft">
          </div>
          <div>
            <label class="label" for="nm-zone">Zone de ramassage *</label>
            <select id="nm-zone" v-model="draft.pickup_zone_id" class="input" required @change="emitDraft">
              <option :value="null" disabled>Choisir…</option>
              <option v-for="z in zones" :key="z.id" :value="z.id">{{ z.full_name }}</option>
            </select>
          </div>
        </div>
        <label class="flex items-start gap-2 text-sm">
          <input v-model="draft.send_access" type="checkbox" class="mt-0.5 h-4 w-4" @change="emitDraft">
          <span>
            Envoyer ses accès par WhatsApp
            <span class="block text-xs text-gray-600">Un seul message : bienvenue, code de la course et lien pour ouvrir son espace.</span>
          </span>
        </label>
        <p class="text-xs text-gray-600">Compte créé à l'enregistrement de la course, avec les tarifs par défaut. Connexion par WhatsApp, sans mot de passe.</p>
      </div>
      <div v-if="known" class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2">
        <span class="text-sm text-amber-900">Ce numéro est déjà <strong>{{ known.business_name }}</strong>.</span>
        <button type="button" class="btn-secondary" @click="pick(known)">La choisir</button>
      </div>
    </div>

    <!-- Recherche -->
    <div v-else class="space-y-2">
      <label class="label mb-0" for="merchant-search">E-commerçant *</label>
      <div class="relative">
        <svg class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7" /><path d="M20 20l-3.5-3.5" /></svg>
        <input
          id="merchant-search" ref="searchEl" v-model="term" class="input pl-9" placeholder="Nom de la boutique ou téléphone" autocomplete="off"
          @input="search" @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)" @keydown.enter.prevent="chooseActive"
        >
      </div>
      <div class="rounded-lg border divide-y overflow-hidden">
        <p class="px-3 py-1.5 text-xs font-semibold text-gray-600 bg-slate-50">
          {{ loading ? 'Recherche…' : term.trim() ? `${results.length} résultat${results.length > 1 ? 's' : ''}` : 'Dernières courses' }}
        </p>
        <button
          v-for="(m, i) in results" :key="m.id" type="button"
          :class="['w-full text-left px-3 py-2.5 flex items-center justify-between gap-3 hover:bg-emerald-50', i === active ? 'bg-emerald-50' : '']"
          @click="pick(m)"
        >
          <span class="min-w-0">
            <span class="block font-medium truncate">{{ m.business_name }}</span>
            <span class="block text-sm text-gray-600">{{ phone(m.phone) }}<span v-if="m.pickup_zone"> · {{ m.pickup_zone.name }}</span></span>
          </span>
          <span v-if="m.last_order_at" class="text-xs text-emerald-700 font-medium shrink-0">{{ ago(m.last_order_at) }}</span>
        </button>
        <p v-if="!loading && term.trim() && !results.length" class="px-3 py-2.5 text-sm text-gray-600">Aucun e-commerçant ne correspond.</p>
        <button v-if="canCreate" type="button" class="w-full text-left px-3 py-3 flex items-center gap-2 font-semibold text-emerald-700 hover:bg-emerald-50" @click="startNew">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
          Nouvel e-commerçant{{ term.trim() ? ` « ${term.trim()} »` : '' }}
        </button>
      </div>
      <p v-if="errors.merchant_id" class="field-error">{{ errors.merchant_id[0] }}</p>
    </div>
  </section>
</template>

<script setup>
import { computed, nextTick, onMounted, reactive, ref } from 'vue'
import http from '../bootstrap/axios'
import { useAuthStore } from '../stores/auth'

/**
 * Choix de l'e-commerçant d'une course : recherche par nom ou téléphone (dernières courses d'abord), ou nouvel
 * e-commerçant créé avec la course (administrateurs et dispatcheurs).
 */
const props = defineProps({
  modelValue: { type: Number, default: null },
  zones: { type: Array, default: () => [] },
  errors: { type: Object, default: () => ({}) },
})
const emit = defineEmits(['update:modelValue', 'new-merchant'])

const auth = useAuthStore()
const canCreate = computed(() => ['admin', 'dispatcher'].includes(auth.user?.role))
const term = ref('')
const results = ref([])
const recent = ref([])
const loading = ref(false)
const active = ref(-1)
const selected = ref(null)
const creating = ref(false)
const known = ref(null)
const searchEl = ref(null)
const draft = reactive({ business_name: '', phone: '', contact_name: '', pickup_zone_id: null, send_access: true })

const phone = (p) => (p || '').replace(/^\+225/, '').replace(/(\d{2})(?=\d)/g, '$1 ')
function ago(at) {
  const days = Math.floor((Date.now() - new Date(at).getTime()) / 86400000)
  return days <= 0 ? "Course aujourd'hui" : days === 1 ? 'Course hier' : `Course il y a ${days} j`
}

// Chiffres seuls : recherche sur le téléphone (sans le 0 de tête, le numéro est enregistré en +225…)
const query = (t) => (/^[\d\s+]+$/.test(t) ? t.replace(/\D/g, '').slice(-8) : t)

onMounted(async () => {
  try {
    recent.value = (await http.get('/merchants', { params: { recent: 1, per_page: 5, status: 'active' } })).data.data
  } catch {
    recent.value = []
  }
  results.value = recent.value
})

let timer
function search() {
  clearTimeout(timer)
  active.value = -1
  const t = term.value.trim()
  if (!t) {
    results.value = recent.value
    return
  }
  loading.value = true
  timer = setTimeout(async () => {
    try {
      results.value = (await http.get('/merchants', { params: { search: query(t), per_page: 8, status: 'active' } })).data.data
    } finally {
      loading.value = false
    }
  }, 200)
}

function move(step) {
  if (!results.value.length) return
  active.value = (active.value + step + results.value.length) % results.value.length
}
function chooseActive() {
  if (results.value[active.value]) pick(results.value[active.value])
  else if (results.value.length === 1) pick(results.value[0])
}

function pick(m) {
  selected.value = m
  creating.value = false
  known.value = null
  emit('new-merchant', null)
  emit('update:modelValue', m.id)
}

async function change() {
  selected.value = null
  term.value = ''
  results.value = recent.value
  emit('update:modelValue', null)
  await nextTick()
  searchEl.value?.focus()
}

function startNew() {
  const t = term.value.trim()
  Object.assign(draft, { business_name: /^[\d\s+]+$/.test(t) ? '' : t, phone: /^[\d\s+]+$/.test(t) ? t : '', contact_name: '', pickup_zone_id: null, send_access: true })
  creating.value = true
  emit('update:modelValue', null)
  emitDraft()
  if (draft.phone) checkPhone()
  nextTick(() => document.getElementById(draft.business_name ? 'nm-phone' : 'nm-name')?.focus())
}

function cancelNew() {
  creating.value = false
  known.value = null
  emit('new-merchant', null)
}

function emitDraft() {
  emit('new-merchant', { ...draft })
}

// Numéro déjà connu : proposer cet e-commerçant plutôt qu'un doublon
let phoneTimer
function checkPhone() {
  clearTimeout(phoneTimer)
  known.value = null
  const digits = draft.phone.replace(/\D/g, '')
  if (digits.length < 8) return
  phoneTimer = setTimeout(async () => {
    const found = (await http.get('/merchants', { params: { search: digits.slice(-8), per_page: 1 } })).data.data
    known.value = found[0] ?? null
  }, 300)
}

defineExpose({ select: pick })
</script>
