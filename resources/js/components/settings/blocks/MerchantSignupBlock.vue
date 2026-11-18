<template>
  <SettingsBlock :title="panel ? 'Inscriptions en ligne' : ''" :panel="panel">
    <label class="flex items-start gap-2 text-sm">
      <input v-model="form.merchant_signup" type="checkbox" class="mt-1">
      <span>Inscriptions en ligne ouvertes<span class="block text-slate-600">Les e-commerçants créent leur compte depuis la page d'accueil (« Créer mon compte ») ; vous êtes prévenu à chaque inscription. Fermées : ils vous envoient une demande, que vous acceptez ou refusez dans E-commerçants.</span></span>
    </label>
    <label v-if="form.merchant_signup" class="ml-6 flex items-start gap-2 text-sm">
      <input v-model="form.merchant_signup_verify" type="checkbox" class="mt-1">
      <span>Vérifier le numéro par WhatsApp ou SMS<span class="block text-slate-600">{{ form.merchant_signup_verify === false
        ? 'Désactivé : le compte est ouvert dès l\'envoi du formulaire, sans code. Pensez à vérifier le numéro des nouveaux inscrits.'
        : 'Le compte est actif dès que le numéro est vérifié. Décochez pour que l\'inscription passe directement, sans code.' }}</span></span>
    </label>
  </SettingsBlock>
</template>

<script setup>
import SettingsBlock from '../SettingsBlock.vue'

defineProps({ form: { type: Object, required: true }, panel: Boolean })
</script>
