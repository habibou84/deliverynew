<template>
  <!-- Panneau qui monte du bas de l'écran (une commande, un message) ; au-dessus de tout -->
  <Teleport to="body">
    <div class="fixed inset-0 z-[60] flex items-end bg-slate-950/60" @click.self="emit('close')">
      <div class="max-h-[88vh] w-full overflow-y-auto rounded-t-[24px] bg-white px-4 pb-[max(1rem,env(safe-area-inset-bottom))] pt-2.5" role="dialog" aria-modal="true" :aria-label="title">
        <div class="mx-auto mb-2 h-1.5 w-10 rounded-full bg-slate-300" />
        <div class="flex items-center gap-2">
          <b class="min-w-0 flex-1 truncate text-[19px]">{{ title }}</b>
          <button type="button" class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-slate-100 text-lg font-black" aria-label="Fermer" @click="emit('close')">✕</button>
        </div>
        <div class="mt-2 space-y-3">
          <slot />
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
defineProps({ title: { type: String, required: true } })
const emit = defineEmits(['close'])
</script>
