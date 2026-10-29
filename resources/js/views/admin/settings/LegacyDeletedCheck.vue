<template>
  <div class="space-y-4">
    <div>
      <RouterLink to="/admin/reglages/reprise" class="text-sm font-medium text-emerald-700">‹ Reprise de l'ancienne application</RouterLink>
      <h1 class="text-xl font-bold">Supprimées dans l'ancienne application : à vérifier</h1>
      <p class="text-sm text-gray-600">
        Ces commandes ont été effacées dans l'ancienne application, mais de l'argent est en jeu ou le colis est chez un
        livreur. « Garder » : la course reste dans Jibiat. « Annuler » : la course est annulée et l'argent pas encore
        réglé disparaît (elle n'a jamais existé). Rien n'est envoyé aux clients.
      </p>
    </div>

    <p v-if="loaded && !orders.length" class="card p-4 text-sm text-gray-600">Plus aucune commande supprimée à vérifier.</p>

    <div v-if="orders.length" class="card overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-slate-50 text-left text-xs text-gray-500">
          <tr><th class="px-3 py-2">Ancien n°</th><th class="px-3">État ici</th><th class="px-3">Destinataire</th><th class="px-3">E-commerçant</th><th class="px-3">Livreur</th><th class="px-3 text-right">À encaisser</th><th class="px-3"></th></tr>
        </thead>
        <tbody>
          <tr v-for="o in orders" :key="o.id" class="border-t">
            <td class="px-3 py-2"><RouterLink :to="`/admin/courses/${o.id}`" class="text-emerald-700">{{ o.legacy_id }}</RouterLink></td>
            <td class="px-3">{{ o.status_label }}<span v-if="o.money" class="block text-xs text-amber-700">{{ o.cash_with_courier ? 'Argent chez le livreur' : 'Argent dû à l\'e-commerçant' }}</span></td>
            <td class="px-3">{{ o.recipient }}</td>
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
      <p v-if="total > orders.length" class="border-t p-3 text-sm text-gray-600">… et {{ total - orders.length }} autres : traitez celles-ci, la suite s'affiche ensuite.</p>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import http, { apiErrorMessage } from '../../../bootstrap/axios'
import { useToastStore } from '../../../stores/toasts'
import { money } from '../../../utils/format'

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
  if (action === 'cancel' && !window.confirm(`Annuler la course ${order.legacy_id} ? L'argent pas encore réglé de cette course disparaît.`)) return
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
