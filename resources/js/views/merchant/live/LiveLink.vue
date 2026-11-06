<template>
  <!-- Live sans WhatsApp relié : les clientes commandent par le lien du live -->
  <section class="m-card space-y-2.5 border-2 border-[var(--app-color)] p-4">
    <p class="text-[17px] font-extrabold">🔗 Le lien de votre live</p>
    <p class="text-[15px] text-slate-700">Vos clientes y voient vos produits et commandent elles-mêmes : la commande arrive chez votre agence.</p>
    <p class="select-all break-all rounded-xl bg-slate-100 px-3 py-2.5 font-mono text-[15px] font-semibold text-slate-900">{{ url }}</p>
    <button type="button" class="m-btn-primary w-full" @click="copy">{{ copied ? 'Lien copié ✓' : 'Copier le lien' }}</button>
    <p class="text-[14px] text-slate-600">Mettez-le dans votre <b>bio</b> et en <b>commentaire épinglé</b> pendant le live.</p>
    <button v-if="!compact" type="button" class="text-[14px] font-semibold text-slate-500 underline" @click="$emit('whatsapp')">Relier plutôt mon WhatsApp</button>
  </section>
</template>

<script setup>
import { ref } from 'vue'
import { useToastStore } from '../../../stores/toasts'

const props = defineProps({ url: { type: String, required: true }, compact: Boolean })
defineEmits(['whatsapp'])
const toasts = useToastStore()
const copied = ref(false)

async function copy() {
  try {
    await navigator.clipboard.writeText(props.url)
    copied.value = true
    setTimeout(() => { copied.value = false }, 2500)
  } catch {
    toasts.error('Copie impossible : appuyez longtemps sur le lien pour le copier.')
  }
}
</script>
