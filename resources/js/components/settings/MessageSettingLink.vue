<template>
  <!-- Un message automatique se coupe à un seul endroit : WhatsApp › Messages automatiques -->
  <p class="flex flex-wrap items-center gap-x-2 text-sm">
    <span :class="['rounded-full px-2 py-0.5 text-xs font-medium ring-1', on ? 'bg-emerald-50 text-emerald-800 ring-emerald-200' : 'bg-slate-100 text-slate-700 ring-slate-300']">
      {{ on ? 'envoyé' : 'coupé' }}
    </span>
    <span>{{ label }}</span>
    <RouterLink to="/admin/whatsapp/messages" class="font-semibold text-blue-700 hover:underline">Régler dans WhatsApp › Messages automatiques</RouterLink>
  </p>
</template>

<script setup>
defineProps({ on: { type: Boolean, default: true }, label: { type: String, required: true } })
</script>
