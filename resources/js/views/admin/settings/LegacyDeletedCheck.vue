<template>
  <div class="space-y-4">
    <div>
      <RouterLink to="/admin/reglages/reprise/controles" class="text-sm font-medium text-emerald-700">‹ Contrôles et ménage</RouterLink>
      <h1 class="text-xl font-bold">Supprimées dans l'ancienne application : à vérifier</h1>
      <p class="text-sm text-slate-600">
        Ces commandes ont été effacées dans l'ancienne application, mais de l'argent est en jeu ou le colis est chez un
        livreur. « Garder » : la course reste dans Jibiat. « Annuler » : la course est annulée et l'argent pas encore
        réglé disparaît (elle n'a jamais existé). Rien n'est envoyé aux clients.
      </p>
    </div>

    <p v-if="loaded && !orders.length" class="card p-4 text-sm text-slate-600">Plus aucune commande supprimée à vérifier.</p>

    <div v-if="orders.length" class="card overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-slate-50 text-left text-xs text-slate-600">
          <tr><th class="px-3 py-2">Ancien n°</th><th class="px-3">Livraison</th><th class="px-3">État ici</th><th class="px-3">Destinataire</th><th class="px-3">E-commerçant</th><th class="px-3">Livreur</th><th class="px-3 text-right">À encaisser</th><th class="px-3"></th></tr>
        </thead>
        <tbody>
          <tr v-for="o in orders" :key="o.id" class="border-t">
            <td class="px-3 py-2"><RouterLink :to="`/admin/courses/${o.id}`" class="text-emerald-700">{{ o.legacy_id }}</RouterLink></td>
            <td class="px-3 whitespace-nowrap">
              {{ o.delivery_date ? date(o.delivery_date) : '—' }}
              <span v-if="o.first_delivery_date && o.first_delivery_date !== o.delivery_date" class="block text-xs text-slate-600">prévue d'abord le {{ date(o.first_delivery_date) }}</span>
            </td>
            <td class="px-3">{{ o.status_label }}<span v-if="o.money" class="block text-xs text-amber-700">{{ o.cash_with_courier ? 'Argent chez le livreur' : 'Argent dû à l\'e-commerçant' }}</span></td>
            <td class="px-3 py-2 min-w-[11rem]">
              <span class="block font-medium">{{ o.recipient || 'Nom non renseigné' }}</span>
              <span v-if="o.delivery_zone || o.delivery_address" class="block text-xs text-slate-600">{{ [o.delivery_zone, o.delivery_address].filter(Boolean).join(' · ') }}</span>
              <span v-for="phone in [o.recipient_phone, o.recipient_phone2].filter(Boolean)" :key="phone" class="mt-1 flex items-center gap-2 whitespace-nowrap">
                <span class="tabular-nums">{{ localPhone(phone) }}</span>
                <a :href="telLink(phone)" class="rounded bg-slate-100 px-1.5 py-0.5 text-xs font-medium text-slate-700" title="Appeler">📞 Appeler</a>
                <a :href="whatsappLink(phone)" target="_blank" rel="noopener" class="rounded bg-emerald-50 px-1.5 py-0.5 text-xs font-medium text-emerald-700" title="WhatsApp">WhatsApp</a>
              </span>
              <span v-if="!o.recipient_phone && !o.recipient_phone2" class="block text-xs text-slate-600">Pas de numéro</span>
            </td>
            <td class="px-3">{{ o.merchant }}</td>
            <td class="px-3">{{ o.courier || '—' }}</td>
            <td class="px-3 text-right tabular-nums whitespace-nowrap">{{ money(o.cod_amount) }}</td>
            <td class="px-3 py-2">
              <div class="flex justify-end gap-2">
                <button type="button" class="btn-secondary" :disabled="busy" @click="resolve(o, 'keep')">Garder</button>
                <button type="button" class="btn-primary" :disabled="busy" @click="resolve(o, 'cancel')">Annuler</button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
      <p v-if="total > orders.length" class="border-t p-3 text-sm text-slate-600">… et {{ total - orders.length }} autres : traitez celles-ci, la suite s'affiche ensuite.</p>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import http, { apiErrorMessage } from '../../../bootstrap/axios'
import { useToastStore } from '../../../stores/toasts'
import { date, localPhone, money, telLink, whatsappLink } from '../../../utils/format'
import { confirmDialog } from '../../../composables/useDialog'

/** Reprise : commandes effacées dans l'ancienne application avec de l'argent en jeu, à garder ou annuler */
const toasts = useToastStore()
const orders = ref([])
const total = ref(0)
const busy = ref(false)
const loaded = ref(false)

async function load() {
  try {
    const { data } = await http.get('/company/legacy-recheck/deleted')
    orders.value = data.data
    total.value = data.meta.total
    loaded.value = true
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function resolve(order, action) {
  if (action === 'cancel' && !await confirmDialog(`Annuler la course ${order.legacy_id} ? L'argent pas encore réglé de cette course disparaît.`)) return
  busy.value = true
  try {
    await http.post(`/company/legacy-recheck/deleted/${order.id}`, { action })
    toasts.success(action === 'keep' ? 'Course gardée.' : 'Course annulée.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
  load()
}

onMounted(load)
</script>
