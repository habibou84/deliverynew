<template>
  <SettingsBlock :title="panel ? 'Inscriptions en ligne' : ''" :panel="panel">
    <label class="flex items-start gap-2 text-sm">
      <input v-model="form.merchant_signup" type="checkbox" class="mt-1">
      <span>Inscriptions en ligne ouvertes<span class="block text-gray-500">Les e-commerçants créent leur compte depuis la page d'accueil (« Créer mon compte »). Le compte est actif dès que le numéro est vérifié par WhatsApp ou SMS ; vous êtes prévenu à chaque inscription. Fermées : ils vous envoient une demande, que vous acceptez ou refusez dans E-commerçants.</span></span>
    </label>
  </SettingsBlock>
</template>

<script setup>
import SettingsBlock from '../SettingsBlock.vue'

defineProps({ form: { type: Object, required: true }, panel: Boolean })
</script>
