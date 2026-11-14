<template>
  <!-- Onglet Plus (téléphone) : tout le reste en grandes icônes -->
  <div class="space-y-3.5">
    <div class="grid grid-cols-2 gap-2.5">
      <component
        :is="t.to ? 'RouterLink' : 'button'"
        v-for="t in tiles"
        :key="t.label"
        :to="t.to"
        type="button"
        class="tap relative flex flex-col items-center gap-1.5 rounded-[20px] bg-white px-2 py-4 text-center shadow-soft active:scale-[.98]"
        @click="t.action?.()"
      >
        <ColorIcon :name="t.icon" :size="46" />
        <span class="text-[14.5px] font-bold text-slate-900">{{ t.label }}</span>
        <span v-if="t.text" class="text-xs leading-tight text-slate-600">{{ t.text }}</span>
        <span v-if="t.warn" class="absolute right-3 top-3 h-2.5 w-2.5 rounded-full bg-orange-500" aria-label="À compléter" />
      </component>
    </div>

    <BottomSheet :open="helpOpen" title="Aide" @close="helpOpen = false">
      <div class="space-y-2.5">
        <p class="text-[15px] text-slate-600">Votre agence de livraison <b>{{ auth.user?.company?.name }}</b> vous répond.</p>
        <a v-if="wa" :href="wa" target="_blank" rel="noopener" class="m-btn-primary flex w-full items-center justify-center !bg-none !bg-[#25d366]">💬 Écrire sur WhatsApp</a>
        <a v-if="companyPhone" :href="telLink(companyPhone)" class="m-btn-secondary flex w-full items-center justify-center">📞 Appeler {{ companyPhone }}</a>
        <RouterLink to="/marchand/live/preparer" class="m-btn-secondary flex w-full items-center justify-center" @click="helpOpen = false">🔴 Comment marche le live</RouterLink>
      </div>
    </BottomSheet>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import ColorIcon from '../../components/mobile/ColorIcon.vue'
import BottomSheet from '../../components/mobile/BottomSheet.vue'
import { useAuthStore } from '../../stores/auth'
import { whatsappLink } from '../../composables/useBranding'
import { telLink } from '../../utils/format'

const auth = useAuthStore()
const helpOpen = ref(false)
const companyPhone = computed(() => auth.user?.company?.phone)
const wa = computed(() => whatsappLink(auth.user?.company?.whatsapp_phone || companyPhone.value, 'Bonjour, j\'ai besoin d\'aide avec mon espace e-commerçant.'))
const m = computed(() => auth.user?.merchant)

const tiles = computed(() => [
  { to: '/marchand/profil', icon: 'profil', label: 'Mon compte', text: 'Mot de passe, WhatsApp, notifications' },
  { to: '/marchand/statistiques', icon: 'stats', label: 'Statistiques', text: 'Taux de livraison' },
  { action: () => { helpOpen.value = true }, icon: 'aide', label: 'Aide', text: 'Votre agence vous répond' },
  { to: '/marchand/notifications', icon: 'cloche', label: 'Notifications' },
  { to: '/marchand/lieu-de-ramassage', icon: 'lieu', label: 'Lieu de ramassage', warn: !m.value?.has_pickup_location },
  auth.can('finance.view') && { to: '/marchand/moyens-de-paiement', icon: 'paiement', label: 'Comment être payé' },
  auth.can('orders.create') && { to: '/marchand/courses/import', icon: 'excel', label: 'Importer des courses' },
  { to: '/marchand/courses', icon: 'colis', label: 'Toutes mes courses' },
].filter(Boolean))
</script>
