<template>
  <!-- Page WhatsApp : connexion du numéro et messages prédéfinis -->
  <nav aria-label="WhatsApp" class="inline-flex flex-wrap gap-1 rounded-xl bg-slate-200 p-1">
    <RouterLink
      v-for="t in TABS" :key="t.to" :to="t.to"
      class="flex min-h-10 items-center rounded-lg px-4 text-sm font-semibold text-slate-700 hover:bg-white/60"
      exact-active-class="!bg-white !text-slate-900 shadow-sm"
    >
      {{ t.label }}
    </RouterLink>
  </nav>
</template>

<script setup>
const TABS = [
  { to: '/admin/whatsapp', label: 'Connexion' },
  { to: '/admin/whatsapp/modeles', label: 'Messages prédéfinis' },
]
</script>
