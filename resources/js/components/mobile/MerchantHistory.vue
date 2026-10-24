<template>
  <!-- Histoire du colis en frise verticale, du plus récent au plus ancien, sans les étapes internes -->
  <ol class="space-y-0">
    <li v-if="next" class="relative flex gap-3 pb-4">
      <span class="absolute left-[9px] top-6 bottom-0 w-0.5 bg-slate-200" />
      <span class="relative mt-0.5 h-5 w-5 shrink-0 rounded-full border-2 border-slate-300 bg-white" />
      <p class="text-sm text-slate-500">{{ next }}<span class="block text-xs">à venir</span></p>
    </li>
    <li v-for="(item, i) in items" :key="item.id" class="relative flex gap-3 pb-4 last:pb-0">
      <span v-if="i < items.length - 1" class="absolute left-[9px] top-6 bottom-0 w-0.5 bg-slate-200" />
      <span :class="['relative mt-0.5 h-5 w-5 shrink-0 rounded-full ring-[3px] ring-white', item.dot, i === 0 ? 'shadow-[0_0_0_2px_currentColor]' : '']" />
      <div class="min-w-0 text-sm">
        <p :class="i === 0 ? 'font-bold' : 'font-medium'">{{ item.text }}</p>
        <p class="text-xs text-slate-500">{{ item.when }}</p>
        <p v-if="item.detail" class="mt-0.5 text-slate-600">{{ item.detail }}</p>
      </div>
    </li>
  </ol>
</template>

<script setup>
import { computed } from 'vue'
import { date } from '../../utils/format'

const props = defineProps({
  // Événements du plus récent au plus ancien
  events: { type: Array, default: () => [] },
  // Prochaine étape attendue (rond vide en haut), ex. « Livrée »
  next: { type: String, default: '' },
})

const STATUS_TEXT = {
  confirmed: 'Course validée',
  picked_up: 'Colis récupéré chez vous',
  at_hub: 'Colis arrivé à l\'entrepôt',
  out_for_delivery: 'En route vers le client',
  delivered: 'Livrée',
  delivery_failed: 'Livraison échouée',
  rescheduled: 'Relivraison prévue',
  return_assigned: 'Retour vers votre boutique',
  returning: 'Retour vers votre boutique',
  returned: 'Colis retourné à votre boutique',
  cancelled: 'Course annulée',
  rejected: 'Course refusée par l\'agence',
  lost: 'Colis déclaré perdu',
}

const time = (value) => {
  const d = new Date(value)
  const sameDay = d.toDateString() === new Date().toDateString()
  const hour = d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
  return sameDay ? hour : `${d.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' })} à ${hour}`
}

function describe(event) {
  if (event.type === 'created') return { text: 'Course créée', dot: 'bg-emerald-700 text-emerald-700' }
  if (event.type === 'status_changed' && STATUS_TEXT[event.to_status]) {
    // Retour à « validée » depuis le ramassage : c'est un ramassage manqué, dit par l'incident
    if (event.to_status === 'confirmed' && event.from_status !== 'pending') return null
    const bad = ['delivery_failed', 'cancelled', 'rejected', 'lost'].includes(event.to_status)
    const moving = ['out_for_delivery', 'rescheduled', 'return_assigned', 'returning'].includes(event.to_status)
    return {
      text: STATUS_TEXT[event.to_status],
      detail: [event.incident_reason?.label, event.rescheduled_to ? `Nouvelle date : ${date(event.rescheduled_to)}` : null, event.note].filter(Boolean).join(' · ') || null,
      dot: bad ? 'bg-orange-500 text-orange-500' : moving ? 'bg-blue-600 text-blue-600' : 'bg-emerald-700 text-emerald-700',
    }
  }
  if (event.type === 'incident') return { text: 'Problème signalé', detail: [event.incident_reason?.label, event.note].filter(Boolean).join(' · ') || null, dot: 'bg-orange-500 text-orange-500' }
  if (event.type === 'return_requested') return { text: 'Retour demandé', detail: event.note, dot: 'bg-violet-600 text-violet-600' }
  if (event.type === 'note' && event.note) return { text: 'Message', detail: event.note, dot: 'bg-sky-400 text-sky-400' }
  if (event.type === 'edited') return { text: 'Livraison modifiée', dot: 'bg-slate-400 text-slate-400' }
  return null
}

const items = computed(() => props.events
  .map((event) => {
    const item = describe(event)
    const who = event.actor_role === 'courier' ? event.actor_name : ['merchant_owner', 'merchant_staff'].includes(event.actor_role) ? 'par vous' : null
    return item && { ...item, id: event.id, when: [time(event.created_at), who].filter(Boolean).join(' · ') }
  })
  .filter(Boolean))
</script>
