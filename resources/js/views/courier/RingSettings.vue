<template>
  <div class="space-y-3">
    <h2 class="text-xl font-extrabold">Ne ratez aucune mission</h2>

    <p v-if="!available" class="m-card p-4 text-sm text-slate-600">
      La sonnerie des missions fonctionne dans l'application Android « Jibiat Livreur ». Dans le navigateur, les
      nouvelles missions arrivent en notification simple.
    </p>
    <template v-else-if="status">
      <p class="text-sm text-slate-600">
        Pour que le téléphone sonne comme un appel à chaque nouveau ramassage ou livraison, même écran verrouillé,
        autorisez ces trois réglages.
      </p>
      <p v-if="!status.available" class="rounded-xl bg-amber-50 p-3 text-sm text-amber-800">
        La sonnerie n'est pas encore activée pour cette version de l'application : demandez la mise à jour à l'agence.
      </p>

      <section v-for="(step, i) in steps" :key="step.key" :class="['m-card p-4 space-y-3', !step.done && i === firstTodo ? 'ring-2 ring-emerald-600' : '']">
        <div class="flex items-center gap-3">
          <span :class="['h-10 w-10 shrink-0 rounded-full grid place-items-center font-extrabold', step.done ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-800']">
            <svg v-if="step.done" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12l5 5L20 7" /></svg>
            <template v-else>{{ i + 1 }}</template>
          </span>
          <span class="min-w-0 flex-1">
            <span class="block font-bold">{{ step.title }}</span>
            <span class="block text-sm text-slate-600">{{ step.done ? 'Autorisé' : step.hint }}</span>
          </span>
        </div>
        <button v-if="!step.done" type="button" :class="i === firstTodo ? 'm-btn-primary' : 'm-btn-secondary'" @click="step.action">Autoriser</button>
      </section>

      <button type="button" class="m-btn-primary" :disabled="!status.available" @click="test">Tester la sonnerie</button>
      <p class="text-center text-xs text-slate-500">La sonnerie de test s'arrête toute seule au bout de quelques secondes.</p>
    </template>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { openDndSettings, openFullScreenSettings, requestNotifications, ringAvailable, ringStatus, testRing } from '../../native/ring'
import { useToastStore } from '../../stores/toasts'

/**
 * Réglages de la sonnerie des missions : notifications, affichage par-dessus l'écran verrouillé, sonnerie en mode
 * « Ne pas déranger ». Proposé une fois à la première ouverture de l'application, puis depuis le profil.
 */
const toasts = useToastStore()
const available = ringAvailable()
const status = ref(null)

async function refresh() {
  status.value = await ringStatus()
}

const steps = computed(() => [
  { key: 'notifications', title: 'Notifications', hint: 'Pour recevoir les missions', done: !!status.value?.notifications, action: async () => { status.value = await requestNotifications() } },
  { key: 'fullScreen', title: "Afficher par-dessus l'écran verrouillé", hint: "Pour l'écran « appel entrant »", done: !!status.value?.fullScreen, action: openFullScreenSettings },
  { key: 'dnd', title: 'Sonner en mode « Ne pas déranger »', hint: 'Conseillé pendant vos heures de service', done: !!status.value?.dnd, action: openDndSettings },
])
const firstTodo = computed(() => steps.value.findIndex((s) => !s.done))

async function test() {
  await testRing()
  toasts.success('Sonnerie de test envoyée.')
}

// Retour des réglages d'Android : l'état est relu
const onVisible = () => !document.hidden && refresh()
onMounted(() => {
  refresh()
  document.addEventListener('visibilitychange', onVisible)
})
onBeforeUnmount(() => document.removeEventListener('visibilitychange', onVisible))
</script>
