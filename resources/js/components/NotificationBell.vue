<template>
  <div class="relative">
    <button
      class="relative p-2 rounded hover:bg-black/5"
      aria-label="Notifications"
      @click="toggle"
    >
      <span class="text-xl">🔔</span>
      <span
        v-if="store.unread"
        class="absolute -top-0.5 -right-0.5 min-w-5 h-5 px-1 rounded-full bg-red-600 text-white text-xs flex items-center justify-center"
      >{{ store.unread > 99 ? '99+' : store.unread }}</span>
    </button>

    <div
      v-if="open"
      class="absolute right-0 mt-2 w-80 max-w-[90vw] bg-white text-slate-800 rounded-lg shadow-xl border z-50"
    >
      <div class="flex items-center justify-between px-3 py-2 border-b">
        <span class="font-semibold text-sm">Notifications</span>
        <button v-if="store.unread" class="text-xs text-blue-600" @click="store.markAllRead()">Tout marquer comme lu</button>
      </div>
      <ul class="max-h-96 overflow-y-auto divide-y">
        <li v-if="!store.items.length" class="p-4 text-sm text-gray-500">Aucune notification.</li>
        <li
          v-for="n in store.items"
          :key="n.id"
          :class="['p-3 text-sm cursor-pointer hover:bg-slate-50', n.read_at ? '' : 'bg-blue-50/50']"
          @click="go(n)"
        >
          <p class="font-medium">{{ n.title }}</p>
          <p class="text-gray-600">{{ n.body }}</p>
          <p class="text-xs text-gray-400 mt-1">{{ dateTime(n.created_at) }}</p>
        </li>
      </ul>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useNotificationStore } from '../stores/notifications'
import { useAuthStore } from '../stores/auth'
import { dateTime } from '../utils/format'

const store = useNotificationStore()
const auth = useAuthStore()
const router = useRouter()
const open = ref(false)

onMounted(() => { if (!store.loaded) store.fetch().catch(() => {}) })

function toggle() { open.value = !open.value }

function go(n) {
  open.value = false
  if (n.href) router.push(n.href)
  else if (n.order_id) router.push(`${auth.homeRoute}/courses/${n.order_id}`)
  else if (n.product_id) router.push(`${auth.homeRoute}/stock`)
}
</script>
