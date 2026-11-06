<template>
  <div v-if="messages.length" class="card p-4 text-sm">
    <h2 class="font-semibold mb-2">Messages WhatsApp / SMS</h2>
    <ul class="space-y-3">
      <li v-for="m in messages" :key="m.id" class="space-y-1">
        <div class="flex justify-between gap-2">
          <span class="font-medium">{{ m.recipient_type === 'recipient' ? 'Destinataire' : 'Marchand' }} · {{ m.to }}</span>
          <MessageStatus :message="m" />
        </div>
        <p class="text-gray-600">{{ m.body }}</p>
        <p v-if="m.error" class="text-red-700 text-xs">{{ m.error }}</p>
        <!-- WhatsApp pas parti (sans carte chez Meta, numéro refusé…) : le même texte, envoyé depuis le WhatsApp de l'agence -->
        <a v-if="m.channel === 'whatsapp' && m.status === 'failed' && m.to" :href="waLink(m)" target="_blank" rel="noopener" class="inline-flex items-center gap-1 rounded-md bg-[#25d366] px-2.5 py-1 text-xs font-semibold text-white">Envoyer depuis mon WhatsApp</a>
        <p class="text-gray-400 text-xs">{{ dateTime(m.created_at) }}</p>
      </li>
    </ul>
  </div>
</template>

<script setup>
import { onMounted, ref, watch } from 'vue'
import http from '../bootstrap/axios'
import MessageStatus from './MessageStatus.vue'
import { dateTime } from '../utils/format'

const props = defineProps({
  orderId: { type: Number, required: true },
  // Incrémenté par le parent à chaque changement de la course
  version: { type: [Number, String], default: 0 },
})

const messages = ref([])
const waLink = (m) => `https://wa.me/${String(m.to).replace(/\D/g, '')}?text=${encodeURIComponent(m.body || '')}`

async function load() {
  try {
    messages.value = (await http.get(`/orders/${props.orderId}/messages`)).data.data
  } catch {
    messages.value = []
  }
}

// Les messages partent en file d'attente : on relit un peu après chaque changement
watch(() => [props.orderId, props.version], () => {
  load()
  setTimeout(load, 3000)
})
onMounted(load)
</script>
