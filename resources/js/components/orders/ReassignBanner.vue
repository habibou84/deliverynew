<template>
  <!-- Course d'origine : le colis est parti avec une autre course (l'ancien code renvoie au nouveau) -->
  <div v-if="order.reassigned_to" class="rounded-xl bg-rose-50 px-4 py-3 text-rose-900 ring-1 ring-rose-200" role="alert">
    <p class="font-bold">Ce colis est devenu <component :is="links ? RouterLink : 'span'" :to="links ? `${base}/${order.reassigned_to.id}` : undefined" class="font-mono underline">{{ order.reassigned_to.tracking_code }}</component></p>
    <p class="text-sm">Il est parti chez un autre client qui avait commandé le même article. N'utilisez plus ce code.</p>
  </div>
  <!-- Nouvelle course : colis repris d'une course en retour, étiquette à changer -->
  <div v-if="order.reassigned_from" :class="['rounded-xl px-4 py-3 ring-1 space-y-2', order.label_to_change ? 'bg-amber-50 text-amber-950 ring-amber-300' : 'bg-slate-50 text-slate-800 ring-slate-200']">
    <p class="font-bold">
      Colis repris de <component :is="links ? RouterLink : 'span'" :to="links ? `${base}/${order.reassigned_from.id}` : undefined" class="font-mono underline">{{ order.reassigned_from.tracking_code }}</component>
      <span v-if="order.label_to_change" class="ml-1 rounded-full bg-amber-500 px-2 py-0.5 text-xs font-bold text-white">Étiquette à changer</span>
      <span v-else class="ml-1 text-sm font-normal text-emerald-700">· nouvelle étiquette collée ✓</span>
    </p>
    <template v-if="order.label_to_change && canConfirm">
      <p class="text-sm">Collez la nouvelle étiquette (« Repris de {{ order.reassigned_from.tracking_code }} »), puis confirmez avec les 4 derniers caractères de son code.</p>
      <form class="flex flex-wrap gap-2" @submit.prevent="confirm">
        <input :id="`label-code-${order.id}`" v-model.trim="code" class="input w-32 font-mono uppercase" maxlength="40" placeholder="Ex. 91KD" aria-label="4 derniers caractères du nouveau code">
        <button class="btn-primary" :disabled="busy || code.length < 4">{{ busy ? '…' : 'Étiquette collée' }}</button>
      </form>
    </template>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { RouterLink } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { useToastStore } from '../../stores/toasts'

/** Liens entre une course en retour réaffectée et la nouvelle course, et confirmation de la nouvelle étiquette */
const props = defineProps({
  order: { type: Object, required: true },
  base: { type: String, default: '/admin/courses' },
  canConfirm: { type: Boolean, default: true },
  // Livreur : pas de lien vers la fiche des courses
  links: { type: Boolean, default: true },
})
const emit = defineEmits(['updated'])
const toasts = useToastStore()
const code = ref('')
const busy = ref(false)

async function confirm() {
  busy.value = true
  try {
    const { data } = await http.post(`/orders/${props.order.id}/label`, { code: code.value })
    toasts.success('Nouvelle étiquette confirmée.')
    code.value = ''
    emit('updated', data.data)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}
</script>
