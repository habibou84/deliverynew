<template>
  <div class="space-y-2">
    <p class="text-sm font-semibold">Pourquoi annulez-vous ? <span class="text-red-600">*</span></p>
    <p v-if="loading" class="text-sm text-gray-500">Chargement…</p>
    <div v-else class="space-y-1.5" role="radiogroup">
      <label
        v-for="r in quota?.reasons || []"
        :key="r.code"
        :class="['flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm', modelValue === r.code ? 'border-emerald-500 bg-emerald-50' : 'border-slate-200']"
      >
        <input type="radio" :value="r.code" :checked="modelValue === r.code" @change="$emit('update:modelValue', r.code)">
        {{ r.label }}
      </label>
    </div>
    <div>
      <label class="label" :for="noteId">Précision {{ modelValue === 'other' ? '*' : '(facultatif)' }}</label>
      <textarea :id="noteId" :value="note" rows="2" :class="mobile ? 'm-input' : 'input'" maxlength="1000" :required="modelValue === 'other'" @input="$emit('update:note', $event.target.value)" />
    </div>
    <template v-if="quota && quota.limit !== null">
      <p v-if="quota.needs_approval" class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">
        ⚠️ Vous avez déjà annulé <b>{{ quota.count }} course{{ quota.count > 1 ? 's' : '' }} aujourd'hui</b>. Au-delà de {{ quota.limit }}, un administrateur doit valider : la course reste active jusqu'à sa décision.
      </p>
      <p v-else class="text-xs text-gray-500">Vos annulations aujourd'hui : {{ quota.count }} sur {{ quota.limit }} sans validation.</p>
    </template>
    <p v-if="quota && !merchant" class="rounded-lg bg-blue-50 px-3 py-2 text-xs text-blue-900">📣 L'e-commerçant est prévenu avec ce motif et votre nom.</p>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import http from '../bootstrap/axios'
import { useAuthStore } from '../stores/auth'

/** Motif d'annulation obligatoire (liste de l'e-commerçant ou de l'entreprise), précision, annulations du jour */
defineProps({
  modelValue: { type: String, default: null },
  note: { type: String, default: '' },
  mobile: { type: Boolean, default: false },
})
defineEmits(['update:modelValue', 'update:note'])

const auth = useAuthStore()
const merchant = computed(() => !!auth.user?.merchant_id)
const quota = ref(null)
const loading = ref(true)
const noteId = `cancel-note-${Math.random().toString(36).slice(2, 8)}`

onMounted(async () => {
  try {
    quota.value = (await http.get('/cancellations/quota')).data.data
  } finally {
    loading.value = false
  }
})

defineExpose({ quota })
</script>
