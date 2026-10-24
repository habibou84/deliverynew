<template>
  <div class="space-y-3">
    <!-- À traiter : seulement ce qui attend une décision -->
    <div class="flex rounded-xl bg-slate-200/70 p-1" role="radiogroup" aria-label="Notifications à afficher">
      <button v-for="t in tabs" :key="t.value" type="button" role="radio" :aria-checked="tab === t.value" :class="['tap flex-1 rounded-lg py-2 text-sm font-bold', tab === t.value ? 'bg-white shadow-sm' : 'text-slate-600']" @click="tab = t.value">
        {{ t.label }}
      </button>
    </div>
    <div v-if="store.unread" class="flex justify-end">
      <button class="text-sm font-bold text-[var(--app-color)]" @click="store.markAllRead()">Tout marquer comme lu</button>
    </div>

    <template v-for="section in sections" :key="section.label">
      <h2 class="pt-1 text-[13px] font-extrabold uppercase tracking-wider text-slate-500">{{ section.label }}</h2>
      <div class="rounded-2xl bg-white ring-1 ring-slate-200 divide-y divide-slate-100">
        <div v-for="item in section.items" :key="item.key" class="flex gap-3 p-3.5">
          <span :class="['h-10 w-10 shrink-0 rounded-full grid place-items-center', item.look.tone]"><AppIcon :name="item.look.icon" class="h-5 w-5" /></span>
          <div class="min-w-0 flex-1 text-sm">
            <button type="button" class="block w-full text-left" @click="open(item)">
              <b class="block text-[14.5px]">{{ item.title }}</b>
              <span class="text-slate-600">{{ item.body }}</span>
              <span class="text-xs text-slate-400"> · {{ item.when }}</span>
            </button>
            <!-- Colis à régler : le choix le plus courant en un toucher -->
            <div v-if="item.decide" class="mt-2 flex gap-2">
              <RouterLink :to="`/marchand/courses/${item.order_id}?action=decider&choix=demain`" class="rounded-lg bg-[var(--app-color)] px-3 py-2 text-[13px] font-bold text-white" @click="markRead(item)">Relivrer demain</RouterLink>
              <RouterLink :to="`/marchand/courses/${item.order_id}`" class="rounded-lg px-3 py-2 text-[13px] font-bold ring-1 ring-slate-300" @click="markRead(item)">Voir</RouterLink>
            </div>
          </div>
          <span v-if="item.unread" class="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full bg-[var(--app-color)]" aria-label="Non lue" />
        </div>
      </div>
    </template>

    <EmptyState v-if="store.loaded && !sections.length" icon="🔔" :title="tab === 'todo' ? 'Rien à traiter' : 'Aucune notification'" :text="tab === 'todo' ? 'Aucun colis n\'attend votre décision. 👍' : 'Vous serez prévenu ici de chaque étape importante.'" />
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import AppIcon from '../../components/mobile/AppIcon.vue'
import EmptyState from '../../components/mobile/EmptyState.vue'
import http from '../../bootstrap/axios'
import { useNotificationStore } from '../../stores/notifications'

const store = useNotificationStore()
const router = useRouter()
const tab = ref('all')
const toDecide = ref(new Set())

const tabs = computed(() => [
  { value: 'todo', label: `À traiter${todo.value.length ? ` · ${todo.value.length}` : ''}` },
  { value: 'all', label: 'Tout' },
])

// Icône et couleur par type de notification
const LOOKS = {
  incident: { icon: 'alert', tone: 'bg-orange-100 text-orange-700' },
  pickup_failed: { icon: 'alert', tone: 'bg-orange-100 text-orange-700' },
  delivered: { icon: 'check', tone: 'bg-emerald-100 text-emerald-700' },
  picked_up: { icon: 'moto', tone: 'bg-blue-100 text-blue-700' },
  out_for_delivery: { icon: 'moto', tone: 'bg-blue-100 text-blue-700' },
  rescheduled: { icon: 'calendar', tone: 'bg-blue-100 text-blue-700' },
  confirmed: { icon: 'check', tone: 'bg-slate-100 text-slate-600' },
  returned: { icon: 'back', tone: 'bg-violet-100 text-violet-700' },
  cancelled: { icon: 'x', tone: 'bg-slate-100 text-slate-600' },
  rejected: { icon: 'ban', tone: 'bg-red-100 text-red-700' },
  note: { icon: 'chat', tone: 'bg-sky-100 text-sky-700' },
  expense: { icon: 'wallet', tone: 'bg-slate-100 text-slate-600' },
  low_stock: { icon: 'store', tone: 'bg-amber-100 text-amber-700' },
  shop_order: { icon: 'store', tone: 'bg-emerald-100 text-emerald-700' },
}
const look = (kind) => LOOKS[kind] || { icon: 'bell', tone: 'bg-slate-100 text-slate-600' }

// Le nom du client plutôt que le code de la course
function titleOf(n) {
  const who = n.recipient_name
  if (!who) return (n.title || '').replace(/^\p{Extended_Pictographic}\s*/u, '')
  return {
    incident: `Échec de livraison chez ${who}`,
    delivered: `Livré à ${who}`,
    picked_up: `Colis de ${who} récupéré`,
    out_for_delivery: `En route vers ${who}`,
    rescheduled: `Livraison de ${who} reportée`,
    confirmed: `Course pour ${who} validée`,
    returned: `Colis de ${who} retourné`,
    cancelled: `Course pour ${who} annulée`,
    rejected: `Course pour ${who} refusée`,
    note: `Message sur le colis de ${who}`,
    expense: `Frais sur le colis de ${who}`,
  }[n.kind] || n.title
}

// Plusieurs colis récupérés ou livrés dans le même quart d'heure : une seule ligne
const GROUPABLE = { picked_up: (n) => `${n} colis récupérés chez vous`, delivered: (n) => `${n} colis livrés`, out_for_delivery: (n) => `${n} colis en route` }

const time = (value) => {
  const d = new Date(value)
  const days = Math.round((new Date().setHours(0, 0, 0, 0) - new Date(d).setHours(0, 0, 0, 0)) / 86400000)
  const hour = d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
  return days === 0 ? hour : days === 1 ? `hier ${hour}` : d.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'short' })
}
const sectionOf = (value) => {
  const days = Math.round((new Date().setHours(0, 0, 0, 0) - new Date(value).setHours(0, 0, 0, 0)) / 86400000)
  return days === 0 ? 'Aujourd\'hui' : days === 1 ? 'Hier' : days < 7 ? 'Cette semaine' : 'Plus ancien'
}

const items = computed(() => {
  const rows = []
  for (const n of store.items) {
    const last = rows[rows.length - 1]
    const close = last && GROUPABLE[n.kind] && last.kind === n.kind && Math.abs(new Date(last.created_at) - new Date(n.created_at)) < 15 * 60000
    if (close) {
      last.list.push(n)
      continue
    }
    rows.push({ ...n, list: [n] })
  }
  return rows.map((r) => {
    const many = r.list.length > 1
    const names = r.list.map((n) => n.recipient_name || n.tracking_code).filter(Boolean)
    return {
      key: r.id,
      kind: r.kind,
      notifications: r.list,
      created_at: r.created_at,
      title: many ? GROUPABLE[r.kind](r.list.length) : titleOf(r),
      body: many ? names.join(', ') : r.body,
      when: time(r.created_at),
      section: sectionOf(r.created_at),
      unread: r.list.some((n) => !n.read_at),
      look: look(r.kind),
      decide: !many && r.order_id && toDecide.value.has(r.order_id),
      order_id: many ? null : r.order_id,
      href: r.href,
      product_id: r.product_id,
    }
  })
})

// À traiter : la dernière notification de chaque colis qui attend encore une décision
const todo = computed(() => {
  const seen = new Set()
  return items.value.filter((i) => {
    if (!i.decide || seen.has(i.order_id)) return false
    seen.add(i.order_id)
    return true
  })
})

const sections = computed(() => {
  const list = tab.value === 'todo' ? todo.value : items.value
  const out = []
  for (const item of list) {
    const label = tab.value === 'todo' ? 'Colis à régler' : item.section
    let section = out.find((s) => s.label === label)
    if (!section) out.push(section = { label, items: [] })
    section.items.push(item)
  }
  return out
})

const markRead = (item) => item.notifications.forEach((n) => store.markRead(n))

function open(item) {
  markRead(item)
  if (item.decide) router.push(`/marchand/courses/${item.order_id}?action=decider`)
  else if (item.href) router.push(item.href)
  else if (item.product_id) router.push('/marchand/stock')
  else if (item.order_id) router.push(`/marchand/courses/${item.order_id}`)
  else if (item.notifications.length > 1) router.push('/marchand')
}

onMounted(async () => {
  store.fetch().catch(() => {})
  try {
    const { data } = await http.get('/orders', { params: { queue: 'to_decide', per_page: 100 } })
    toDecide.value = new Set(data.data.map((o) => o.id))
    if (data.data.length) tab.value = 'todo'
  } catch {
    // Sans réponse : seul l'onglet « Tout » est utile
  }
})
</script>
