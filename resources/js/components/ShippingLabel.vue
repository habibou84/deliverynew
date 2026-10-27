<template>
  <!-- Une étiquette de colis : code et QR de suivi, expéditeur, destinataire, zone, jour de livraison, montant à encaisser -->
  <div class="shipping-label border-2 border-black bg-white p-3 text-black space-y-2">
    <div class="flex items-start justify-between gap-2">
      <div class="min-w-0">
        <p class="text-xs">{{ companyName }}</p>
        <p class="font-mono text-lg font-bold">{{ order.tracking_code }}</p>
        <p class="text-xs">Créée le {{ dateTime(order.created_at) }}</p>
        <p v-if="order.delivery.scheduled_date" class="mt-1 inline-block rounded border border-black px-1.5 text-sm font-bold">
          📅 Livraison le {{ date(order.delivery.scheduled_date) }}<template v-if="order.delivery.time_slot"> · {{ order.delivery.time_slot }}</template>
        </p>
      </div>
      <img v-if="qr" :src="qr" alt="QR code de suivi" class="h-28 w-28 shrink-0">
    </div>
    <div class="border-t border-black pt-2">
      <p class="text-xs uppercase">Expéditeur</p>
      <p class="font-semibold">{{ order.merchant?.business_name }}</p>
      <p class="text-sm">{{ order.pickup.zone_name }} · {{ order.pickup.phone }}</p>
    </div>
    <div class="border-t border-black pt-2">
      <p class="text-xs uppercase">Destinataire</p>
      <p class="text-lg font-bold">{{ order.recipient.name || '—' }}</p>
      <p class="font-semibold">{{ order.recipient.phone }}<span v-if="order.recipient.phone2"> / {{ order.recipient.phone2 }}</span></p>
      <p class="text-2xl font-bold uppercase">{{ order.delivery.zone_name }}</p>
      <p class="text-sm">{{ order.delivery.address }}</p>
      <p v-if="order.delivery.landmark" class="text-sm">Repère : {{ order.delivery.landmark }}</p>
    </div>
    <div class="flex items-end justify-between border-t border-black pt-2">
      <div>
        <p class="text-xs uppercase">À encaisser</p>
        <p class="text-2xl font-bold">{{ money(order.amounts.cod_amount) }}</p>
      </div>
      <p class="text-sm font-semibold">
        <span v-if="order.package.is_fragile">FRAGILE </span>
        <span v-if="order.package.is_express">EXPRESS</span>
      </p>
    </div>
  </div>
</template>

<script setup>
import { ref, watch } from 'vue'
import QRCode from 'qrcode'
import { siteOrigin } from '../native'
import { date, dateTime, money } from '../utils/format'

const props = defineProps({ order: { type: Object, required: true }, companyName: { type: String, default: '' } })
const qr = ref('')

// Le QR code mène à la page de suivi publique (scan au ramassage, au dépôt et à la remise)
watch(() => props.order.tracking_code, async (code) => {
  qr.value = await QRCode.toDataURL(`${siteOrigin()}/suivi/${code}`, { margin: 1, width: 240 }).catch(() => '')
}, { immediate: true })
</script>
