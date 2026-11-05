<template>
  <div class="space-y-3.5 pb-6">
    <p v-if="error" class="rounded-2xl bg-red-50 px-4 py-3 text-[15px] text-red-700" role="alert">{{ error }}</p>
    <p v-if="!s && !error" class="m-card p-6 text-center text-slate-500">Chargement…</p>

    <template v-if="s">
      <div class="guide">
        <p class="guide-title">Maintenant</p>
        <p>1. Préparez les colis : l'agence a les commandes.</p>
        <p>2. Écrivez aux clientes en attente.</p>
      </div>

      <section class="rounded-[22px] bg-slate-900 p-4 text-white">
        <p class="text-[14px] text-slate-300">{{ s.live.name }} · {{ s.duration_minutes }} min</p>
        <p class="text-[32px] font-extrabold">{{ money(s.totals.amount) }}</p>
        <div class="mt-2 grid grid-cols-3 gap-2 text-center">
          <div class="rounded-xl bg-slate-800 py-2"><b class="block text-[20px]">{{ s.totals.orders }}</b><span class="text-[13px] text-slate-300">commandes</span></div>
          <div class="rounded-xl bg-slate-800 py-2"><b class="block text-[20px]">{{ s.deliveries.created }}</b><span class="text-[13px] text-slate-300">courses créées</span></div>
          <div class="rounded-xl bg-slate-800 py-2"><b class="block text-[20px]">{{ s.new_customers }}</b><span class="text-[13px] text-slate-300">nouvelles clientes</span></div>
        </div>
      </section>

      <section class="m-card space-y-1.5 p-4 text-[16px]">
        <p class="text-[14px] font-extrabold uppercase tracking-wide">Le bilan</p>
        <p v-if="s.best_item && s.best_item.sold"><b>Meilleur produit :</b> {{ s.best_item.code }} {{ s.best_item.label || s.best_item.name }} ({{ s.best_item.sold }} vendu{{ s.best_item.sold > 1 ? 's' : '' }}).</p>
        <p v-if="s.peak_minute !== null && s.peak_minute !== undefined"><b>Meilleur moment :</b> {{ s.peak_minute ? `de la ${s.peak_minute}e à la ${s.peak_minute + 5}e minute` : 'les 5 premières minutes' }}.</p>
        <p><b>Livraisons :</b> {{ s.deliveries.delivered }} livrée{{ s.deliveries.delivered > 1 ? 's' : '' }}, {{ s.deliveries.refused }} refusée{{ s.deliveries.refused > 1 ? 's' : '' }}, les autres en cours.</p>
      </section>

      <section class="m-card divide-y divide-slate-100">
        <p class="px-4 py-2.5 text-[13px] font-semibold text-slate-500">PAR PRODUIT</p>
        <div v-for="i in s.items" :key="i.id" class="flex items-center gap-3 px-4 py-2.5 text-[15px]">
          <span class="grid h-9 min-w-[44px] place-items-center rounded-lg bg-slate-900 px-1.5 text-[14px] font-extrabold text-white">{{ i.code }}</span>
          <span class="min-w-0 flex-1 truncate"><b>{{ i.label || i.name }}</b> · {{ i.sold }} sur {{ i.quantity }}</span>
          <span class="shrink-0 font-bold">{{ money(i.sold * i.price) }}</span>
        </div>
      </section>

      <section v-if="waiting.length" class="m-card divide-y divide-slate-100">
        <p class="px-4 py-2.5 text-[13px] font-semibold text-slate-500">LISTE D'ATTENTE · {{ waiting.length }}</p>
        <div v-for="o in waiting" :key="o.id" class="flex items-center gap-3 px-4 py-2.5 text-[15px]">
          <span class="min-w-0 flex-1"><b>{{ o.customer_name }}</b> · {{ o.code }} {{ o.name }}</span>
          <a :href="whatsappLink(o.customer_phone)" target="_blank" rel="noopener" class="shrink-0 rounded-xl bg-emerald-600 px-3 py-2 text-[14px] font-bold text-white">Écrire</a>
        </div>
      </section>

      <RouterLink to="/marchand/live/preparer" class="m-btn-primary w-full">Préparer le prochain live</RouterLink>
      <RouterLink :to="`/marchand/live/${s.live.id}`" class="m-btn-secondary w-full">Voir les commandes du live</RouterLink>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import http, { apiErrorMessage } from '../../../bootstrap/axios'
import { money, whatsappLink } from '../../../utils/format'

/** Après le live : chiffres, bilan calculé, produits, clientes en liste d'attente */
const route = useRoute()
const s = ref(null)
const error = ref('')
const waiting = computed(() => (s.value?.orders || []).filter((o) => o.status === 'waiting'))

onMounted(async () => {
  try {
    s.value = (await http.get(`/live/${route.params.id}/summary`)).data.data
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
})
</script>

<style scoped>
.guide {
  border-radius: 18px;
  background: #1e40af;
  color: #fff;
  padding: 14px 16px;
  font-size: 18px;
  font-weight: 700;
  line-height: 1.35;
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.guide-title {
  font-size: 14px;
  font-weight: 800;
  color: #bfdbfe;
  text-transform: uppercase;
  letter-spacing: .04em;
}
</style>
