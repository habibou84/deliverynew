<template>
  <div ref="root" class="relative">
    <input
      v-model="q" type="search" class="w-full rounded bg-white/10 px-3 py-1.5 text-white placeholder:text-slate-400 sm:w-56 sm:focus:w-72 focus:bg-white focus:text-slate-900 focus:outline-none transition-all"
      placeholder="🔎 Rechercher partout…" aria-label="Rechercher dans toute la plateforme"
      @input="debounced" @focus="open = !!results" @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)" @keydown.enter.prevent="go(flat[active])" @keydown.esc="open = false"
    >
    <div v-if="open && results" class="absolute left-0 right-0 z-50 mt-2 sm:left-auto sm:w-[30rem] sm:max-w-[90vw] rounded-xl bg-white p-2 text-sm text-slate-900 shadow-2xl ring-1 ring-slate-200">
      <p v-if="!flat.length" class="p-3 text-slate-500">Aucun résultat pour « {{ q }} ».</p>
      <template v-for="group in GROUPS" :key="group.key">
        <template v-if="results[group.key]?.length">
          <p class="px-2 pt-2 pb-1 text-xs font-semibold uppercase text-slate-400">{{ group.label }}</p>
          <button
            v-for="item in results[group.key]" :key="group.key + item.id" type="button"
            :class="['block w-full rounded-lg px-2 py-2 text-left hover:bg-sky-50', flat[active] === item ? 'bg-sky-50' : '']"
            @click="go(item)"
          >
            <template v-if="group.key === 'users'"><b>{{ item.name }}</b> · {{ item.phone }} <span class="text-slate-500">— {{ item.role }}{{ item.company ? ` chez ${item.company.name}` : '' }}{{ item.status !== 'active' ? ' (suspendu)' : '' }}</span></template>
            <template v-else-if="group.key === 'companies'"><b>{{ item.name }}</b> <span class="text-slate-500">— {{ host(item.url) }}{{ item.phone ? ` · ${item.phone}` : '' }}</span></template>
            <template v-else-if="group.key === 'orders'"><b>{{ item.tracking_code }}</b> <span class="text-slate-500">— {{ item.company?.name }} · {{ item.status_label }}{{ item.recipient_phone ? ` · ${item.recipient_phone}` : '' }}</span></template>
            <template v-else><b>{{ item.number }}</b> <span class="text-slate-500">— {{ item.company?.name }} · {{ money(item.total) }}</span></template>
          </button>
        </template>
      </template>
      <p class="mt-2 border-t px-2 pt-2 text-xs text-slate-400">Entrée pour ouvrir · ↑↓ pour choisir · chaque recherche est notée au journal</p>
    </div>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import http from '../../bootstrap/axios'
import { CONSOLE_ROLES } from '../../roles'
import { useAuthStore } from '../../stores/auth'
import { money } from '../../utils/format'

/**
 * Recherche dans toute la plateforme : utilisateurs, entreprises, courses, factures.
 */
const GROUPS = [
  { key: 'users', label: 'Utilisateurs' },
  { key: 'companies', label: 'Entreprises' },
  { key: 'orders', label: 'Courses' },
  { key: 'invoices', label: 'Factures' },
]

const router = useRouter()
const auth = useAuthStore()
const root = ref(null)
const q = ref('')
const results = ref(null)
const open = ref(false)
const active = ref(0)
let timer = null

const flat = computed(() => GROUPS.flatMap((g) => (results.value?.[g.key] ?? []).map((item) => Object.assign(item, { group: g.key }))))
const host = (url) => { try { return new URL(url).host } catch { return url } }

function debounced() {
  clearTimeout(timer)
  if (q.value.trim().length < 2) {
    results.value = null
    open.value = false
    return
  }
  timer = setTimeout(search, 300)
}

async function search() {
  try {
    results.value = (await http.get('/console/search', { params: { q: q.value.trim() } })).data.data
    active.value = 0
    open.value = true
  } catch {
    results.value = null
  }
}

function move(step) {
  if (!flat.value.length) return
  active.value = (active.value + step + flat.value.length) % flat.value.length
}

// Une entreprise (ou celle de l'utilisateur ou de la course) ouvre sa fiche ; une facture, sa page
function go(item) {
  if (!item) return
  open.value = false
  if (item.group === 'invoices' && CONSOLE_ROLES.accounting.includes(auth.role)) return router.push(`/console/factures/${item.id}`)
  const companyId = item.group === 'companies' ? item.id : item.company?.id
  if (companyId) router.push(`/console/entreprises/${companyId}`)
}

function outside(event) {
  if (root.value && !root.value.contains(event.target)) open.value = false
}
onMounted(() => document.addEventListener('click', outside))
onBeforeUnmount(() => document.removeEventListener('click', outside))
</script>
