<template>
  <!-- Colis non reçus au dépôt (écarts de remise) : le livreur confirme ou conteste, un administrateur tranche -->
  <div class="space-y-4">
    <p class="text-sm text-slate-600">
      Un colis à rendre que l'agent n'a pas reçu (au point de caisse ou au contrôle du bac) est noté ici avec son motif. Le livreur est prévenu :
      « je l'ai encore » clôt l'écart ; « je l'ai remis » est une contestation qu'un administrateur tranche, jamais l'agent qui a fait la réception.
    </p>

    <section class="space-y-2">
      <h2 class="font-semibold">À trancher ({{ disputed.length }})</h2>
      <p v-if="!disputed.length" class="card p-4 text-sm text-slate-600">Aucune contestation.</p>
      <article v-for="g in disputed" :key="g.id" class="card p-4 space-y-2 border-l-4 border-red-500">
        <div class="flex flex-wrap items-start justify-between gap-2">
          <p class="font-semibold">
            <RouterLink :to="`/admin/courses/${g.order.id}`" class="font-mono text-blue-700">{{ g.order.tracking_code }}</RouterLink>
            · {{ g.order.recipient_name }} <span class="text-sm font-normal text-slate-600">· {{ g.order.merchant }} · {{ g.order.status_label }}</span>
          </p>
          <span class="text-xs text-slate-600">{{ g.source === 'drop_control' ? 'Contrôle du bac' : 'Point de caisse' }} · {{ dateTime(g.created_at) }}</span>
        </div>
        <ul class="text-sm space-y-1">
          <li>🧾 <strong>{{ g.reporter.name || 'Agent' }}</strong> l'a noté non reçu : {{ g.reason_label }}<template v-if="g.note"> « {{ g.note }} »</template></li>
          <li>🛵 <strong>{{ g.courier.name }}</strong> dit l'avoir remis ({{ dateTime(g.answered_at) }})</li>
        </ul>
        <p class="text-xs text-slate-600">
          Ces 30 jours : {{ g.reporter.name || 'l\'agent' }} a noté {{ g.reporter.gaps_30d }} colis non reçu(s), dont {{ g.reporter.at_hub_30d }} finalement au dépôt ·
          {{ g.courier.name }} a {{ g.courier_history.gaps_30d }} écart(s), dont {{ g.courier_history.rejected_30d }} contestation(s) rejetée(s).
        </p>
        <div v-if="canResolve" class="flex flex-wrap gap-2 pt-1">
          <button type="button" class="btn-success" :disabled="busy" @click="resolve(g, 'at_hub')">Il est au dépôt</button>
          <button type="button" class="btn-secondary" :disabled="busy" @click="resolve(g, 'courier')">Le livreur l'a encore</button>
          <RouterLink :to="`/admin/courses/${g.order.id}`" class="btn-secondary">Introuvable : déclarer perdu</RouterLink>
        </div>
        <p v-else class="text-xs text-slate-600">Un administrateur tranche cet écart.</p>
      </article>
    </section>

    <section v-if="waiting.length" class="space-y-2">
      <h2 class="font-semibold">En attente de la réponse du livreur ({{ waiting.length }})</h2>
      <div class="card divide-y text-sm">
        <div v-for="g in waiting" :key="g.id" class="p-3 flex flex-wrap justify-between gap-2">
          <span><span class="font-mono">{{ g.order.tracking_code }}</span> · {{ g.courier.name }} · {{ g.reason_label }}<template v-if="g.note"> « {{ g.note }} »</template></span>
          <span class="text-slate-600">Noté par {{ g.reporter.name }} le {{ dateTime(g.created_at) }}</span>
        </div>
      </div>
    </section>

    <details v-if="closed.length" class="card p-4">
      <summary class="cursor-pointer font-semibold">Écarts clos ces 30 jours ({{ closed.length }})</summary>
      <div class="mt-3 divide-y text-sm">
        <div v-for="g in closed" :key="g.id" class="py-2 flex flex-wrap justify-between gap-2">
          <span><span class="font-mono">{{ g.order?.tracking_code }}</span> · {{ g.courier.name }} · {{ g.reason_label }}</span>
          <span class="text-slate-600">{{ outcome(g) }}</span>
        </div>
      </div>
    </details>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { confirmDialog } from '../../composables/useDialog'
import { useToastStore } from '../../stores/toasts'
import { dateTime } from '../../utils/format'

const emit = defineEmits(['changed'])
const toasts = useToastStore()
const gaps = ref([])
const canResolve = ref(false)
const busy = ref(false)

const disputed = computed(() => gaps.value.filter((g) => g.courier_answer === 'handed' && !g.resolution))
const waiting = computed(() => gaps.value.filter((g) => !g.courier_answer && !g.resolution))
const closed = computed(() => gaps.value.filter((g) => g.resolution))

function outcome(g) {
  if (g.courier_answer === 'kept') return 'Le livreur a confirmé l\'avoir encore'
  if (g.resolution === 'at_hub') return g.resolved_by ? `Tranché par ${g.resolved_by} : au dépôt` : 'Rendu au dépôt'
  return `Tranché par ${g.resolved_by || '—'} : chez le livreur`
}

async function load() {
  try {
    const { data } = await http.get('/parcel-gaps')
    gaps.value = data.data
    canResolve.value = data.can_resolve
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function resolve(g, decision) {
  const text = decision === 'at_hub'
    ? `Le colis ${g.order.tracking_code} est au dépôt : il ne sera plus noté chez ${g.courier.name}, et l'écart est imputé à ${g.reporter.name || 'l\'agent'}. Confirmer ?`
    : `Le colis ${g.order.tracking_code} reste noté chez ${g.courier.name}, qui doit le rapporter. Confirmer ?`
  if (!await confirmDialog(text)) return
  busy.value = true
  try {
    await http.post(`/parcel-gaps/${g.id}/resolve`, { decision })
    toasts.success(`Décision enregistrée : ${g.courier.name} est prévenu.`)
    await load()
    emit('changed')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

onMounted(load)
</script>
