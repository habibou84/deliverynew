<template>
  <div class="space-y-3">
    <div class="flex justify-end">
      <button v-if="store.unread" class="text-sm font-medium text-[var(--app-color)]" @click="store.markAllRead()">Tout marquer comme lu</button>
    </div>
    <EmptyState v-if="store.loaded && !store.items.length" icon="🔔" title="Aucune notification" text="Vous serez prévenu ici de chaque étape importante." />
    <button
      v-for="n in store.items"
      :key="n.id"
      class="w-full text-left m-card p-4 flex gap-3 active:bg-slate-50"
      @click="open(n)"
    >
      <span :class="['mt-1 h-2.5 w-2.5 rounded-full shrink-0', n.read_at ? 'bg-transparent' : 'bg-[var(--app-color)]']" />
      <span class="flex-1">
        <span class="block font-semibold">{{ n.title }}</span>
        <span class="block text-sm text-slate-600">{{ n.body }}</span>
        <span class="block text-xs text-slate-400 mt-1">{{ dateTime(n.created_at) }}</span>
      </span>
    </button>
  </div>
</template>

<script setup>
import { onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import EmptyState from '../../components/mobile/EmptyState.vue'
import { useNotificationStore } from '../../stores/notifications'
import { dateTime } from '../../utils/format'

const store = useNotificationStore()
const router = useRouter()
const route = useRoute()

onMounted(() => store.fetch().catch(() => {}))

function open(n) {
  if (n.product_id && route.path.startsWith('/marchand')) {
    router.push('/marchand/stock')
    return
  }
  if (!n.order_id) return
  // Livreur : ses missions ; marchand : la fiche de la course
  router.push(route.path.startsWith('/livreur') ? '/livreur' : `/marchand/courses/${n.order_id}`)
}
</script>
