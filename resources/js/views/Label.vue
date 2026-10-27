<template>
  <div class="label-page p-4">
    <div class="no-print mb-4 flex flex-wrap items-center gap-3">
      <button type="button" class="btn-secondary" @click="back">← Retour</button>
      <button class="btn-primary" :disabled="!orders.length" @click="print">🖨️ Imprimer {{ orders.length > 1 ? `les ${orders.length} étiquettes` : "l'étiquette" }}</button>
      <div class="flex rounded-lg bg-slate-100 p-1 text-sm" role="radiogroup" aria-label="Format d'impression">
        <button
          v-for="f in FORMATS" :key="f.value" type="button" role="radio" :aria-checked="format === f.value"
          :class="['rounded-md px-3 py-1.5', format === f.value ? 'bg-white font-semibold shadow-sm' : 'text-gray-600']"
          @click="format = f.value"
        >{{ f.label }}</button>
      </div>
      <span class="text-sm text-gray-500">{{ FORMATS.find((f) => f.value === format).help }}</span>
      <p v-if="missing" class="w-full text-sm text-amber-700">{{ missing }} course(s) introuvable(s) ou hors de votre accès : non imprimée(s).</p>
    </div>

    <p v-if="loading" class="text-gray-500">Chargement…</p>
    <p v-else-if="error" class="text-red-700">{{ error }}</p>

    <!-- Rouleau : une étiquette par page ; A4 : 4 étiquettes par feuille -->
    <template v-else-if="format === 'roll'">
      <ShippingLabel v-for="o in orders" :key="o.id" :order="o" :company-name="companyName" class="label-roll mb-4" />
    </template>
    <template v-else>
      <div v-for="(sheet, i) in sheets" :key="i" class="sheet-a4 mb-4">
        <ShippingLabel v-for="o in sheet" :key="o.id" :order="o" :company-name="companyName" class="label-a4" />
      </div>
    </template>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../bootstrap/axios'
import ShippingLabel from '../components/ShippingLabel.vue'
import { useAuthStore } from '../stores/auth'

const FORMATS = [
  { value: 'roll', label: 'Rouleau 10 × 15 cm', help: 'Imprimante d\'étiquettes : une étiquette par page.' },
  { value: 'a4', label: 'Feuille A4', help: 'Imprimante ordinaire : 4 étiquettes par feuille, à découper.' },
]
const FORMAT_KEY = 'jibiat.label-format'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const orders = ref([])
const loading = ref(true)
const error = ref('')
const missing = ref(0)
const companyName = computed(() => auth.user?.company?.name ?? '')

// Format retenu sur cet appareil
const format = ref((() => {
  try {
    return localStorage.getItem(FORMAT_KEY) === 'a4' ? 'a4' : 'roll'
  } catch {
    return 'roll'
  }
})())
watch(format, (v) => {
  try {
    localStorage.setItem(FORMAT_KEY, v)
  } catch {
    // Stockage indisponible : le choix vaut pour cette page
  }
})

const sheets = computed(() => {
  const out = []
  for (let i = 0; i < orders.value.length; i += 4) out.push(orders.value.slice(i, i + 4))
  return out
})

// Taille de page de l'impression selon le format
const pageStyle = document.createElement('style')
watch(format, (v) => {
  pageStyle.textContent = v === 'a4' ? '@page { size: A4; margin: 0.5cm; }' : '@page { size: 10cm 15cm; margin: 0; }'
}, { immediate: true })

// Ouverte dans un nouvel onglet (équipe) ou dans l'application (e-commerçant)
function back() {
  if (window.history.length > 1) router.back()
  else window.close()
}

function print() {
  window.print()
}

onMounted(async () => {
  document.head.appendChild(pageStyle)
  const ids = route.params.id
    ? [Number(route.params.id)]
    : String(route.query.ids || '').split(',').map(Number).filter((n) => Number.isInteger(n) && n > 0).slice(0, 100)
  if (!ids.length) {
    error.value = 'Aucune course à imprimer.'
    loading.value = false
    return
  }
  try {
    orders.value = (await http.get('/orders/labels', { params: { ids } })).data.data
    missing.value = ids.length - orders.value.length
    if (!orders.value.length) error.value = 'Aucune de ces courses n\'est accessible.'
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
})
onBeforeUnmount(() => pageStyle.remove())
</script>

<style>
.label-roll { width: 10cm; min-height: 15cm; }
.sheet-a4 { display: grid; grid-template-columns: 10cm 10cm; gap: 0.5cm; width: 20.5cm; }
.label-a4 { width: 10cm; height: 13.9cm; overflow: hidden; }
@media print {
  .no-print { display: none; }
  .label-page { padding: 0; }
  .label-roll { margin: 0; break-after: page; }
  .label-roll:last-child { break-after: auto; }
  .sheet-a4 { margin: 0; break-after: page; }
  .sheet-a4:last-child { break-after: auto; }
  .label-a4 { break-inside: avoid; }
}
</style>
