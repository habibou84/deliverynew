<template>
  <!-- Carte d'une course : colis à la couleur de l'état, barre en 4 traits, glisser à gauche pour appeler ou écrire -->
  <div class="relative overflow-hidden rounded-2xl" @touchstart.passive="start" @touchmove="move" @touchend="end">
    <div v-show="offset" class="absolute inset-y-0 right-0 flex">
      <a :href="telLink(order.recipient.phone)" class="tap w-[72px] grid place-items-center content-center gap-1 bg-teal-700 text-white text-xs font-bold">
        <AppIcon name="phone" class="h-5 w-5" />Appeler
      </a>
      <a :href="whatsapp" target="_blank" rel="noopener" class="tap w-[72px] grid place-items-center content-center gap-1 bg-green-600 text-white text-xs font-bold">
        <AppIcon name="chat" class="h-5 w-5" />WhatsApp
      </a>
    </div>

    <div class="relative bg-white ring-1 ring-slate-200 rounded-2xl" :style="{ transform: `translateX(${offset}px)`, transition: dragging ? 'none' : 'transform .2s ease' }">
      <RouterLink :to="to" class="flex items-start gap-3 p-3.5 active:bg-slate-50 rounded-2xl" @click="onClick">
        <span :class="['h-11 w-11 shrink-0 rounded-xl grid place-items-center', stage.badge]" aria-hidden="true">
          <AppIcon name="box" class="h-[22px] w-[22px]" />
        </span>
        <div class="min-w-0 flex-1">
          <div class="flex items-baseline justify-between gap-2">
            <p class="font-bold text-[15.5px] truncate">{{ order.recipient.name || localPhone(order.recipient.phone) }}</p>
            <p class="shrink-0 font-extrabold text-[15.5px]">{{ money(order.amounts.cod_amount) }}</p>
          </div>
          <p class="text-[13px] text-slate-500 truncate">{{ place }}</p>
          <div class="mt-2 flex gap-[3px]" :aria-label="`Étape ${stage.step} sur 4`">
            <i v-for="n in 4" :key="n" :class="['block h-[5px] flex-1 rounded', n <= stage.step ? stage.bar : 'bg-slate-200']" />
          </div>
          <div class="mt-2 flex items-center justify-between gap-2 text-[13px] font-bold">
            <span :class="['truncate', stage.color]">{{ stage.text }}</span>
            <span v-if="later" class="shrink-0 rounded-full bg-sky-50 px-2 py-0.5 text-[11px] text-sky-700">{{ later }}</span>
            <span v-else-if="stage.aside" class="shrink-0 font-semibold text-slate-500">{{ stage.aside }}</span>
            <AppIcon v-else-if="order.status === 'delivered'" name="check" class="h-4 w-4 shrink-0 text-emerald-700" />
          </div>
        </div>
      </RouterLink>
      <div v-if="$slots.actions" class="flex gap-2 px-3.5 pb-3.5">
        <slot name="actions" />
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import AppIcon from './AppIcon.vue'
import { localPhone, money, telLink } from '../../utils/format'
import { laterDay } from '../../utils/merchantStatus'
import { orderStage } from '../../utils/orderStage'
import { openCard } from '../../composables/useSwipeCards'

const props = defineProps({
  order: { type: Object, required: true },
  to: { type: String, required: true },
})

const stage = computed(() => orderStage(props.order))
const later = computed(() => laterDay(props.order))

// « Adjamé · Résidence Les Palmiers » sans répéter la commune
const place = computed(() => {
  const { zone_name: zone, address } = props.order.delivery
  if (!address) return zone
  return zone && !address.toLowerCase().startsWith(zone.toLowerCase()) ? `${zone} · ${address}` : address
})

const whatsapp = computed(() => `https://wa.me/${(props.order.recipient.phone || '').replace(/\D/g, '')}`)

// Glisser vers la gauche : les deux gestes rapides apparaissent (une seule carte ouverte à la fois)
const OPEN = -144
const offset = ref(0)
const dragging = ref(false)
let x0 = 0
let y0 = 0
let base = 0
let horizontal = null
let moved = false

watch(openCard, (id) => { if (id !== props.order.id) offset.value = 0 })

function start(e) {
  x0 = e.touches[0].clientX
  y0 = e.touches[0].clientY
  base = offset.value
  horizontal = null
  moved = false
}

function move(e) {
  const dx = e.touches[0].clientX - x0
  const dy = e.touches[0].clientY - y0
  if (horizontal === null && (Math.abs(dx) > 8 || Math.abs(dy) > 8)) horizontal = Math.abs(dx) > Math.abs(dy)
  if (!horizontal) return
  e.preventDefault()
  dragging.value = true
  moved = true
  offset.value = Math.max(OPEN, Math.min(0, base + dx))
}

function end() {
  if (!dragging.value) return
  dragging.value = false
  offset.value = offset.value < OPEN / 2 ? OPEN : 0
  if (offset.value) openCard.value = props.order.id
}

// Un toucher sur une carte ouverte la referme au lieu d'ouvrir la course
function onClick(e) {
  if (moved || offset.value) {
    e.preventDefault()
    offset.value = 0
    moved = false
  }
}
</script>
