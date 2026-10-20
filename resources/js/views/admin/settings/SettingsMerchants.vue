<template>
  <SettingsPage icon="🏪" title="E-commerçants" intro="Comment les e-commerçants rejoignent votre entreprise.">
    <template v-if="form">
      <section class="card p-4 space-y-3">
        <label class="flex items-start gap-2 text-sm">
          <input v-model="form.merchant_signup" type="checkbox" class="mt-1">
          <span>Inscriptions en ligne ouvertes<span class="block text-gray-500">Les e-commerçants créent leur compte depuis la page d'accueil (« Créer mon compte »). Le compte est actif dès que le numéro est vérifié par WhatsApp ou SMS ; vous êtes prévenu à chaque inscription.</span></span>
        </label>
      </section>
      <SaveBar :dirty="dirty" :saving="saving" :error="error" @save="save" @reset="reset" />
    </template>
  </SettingsPage>
</template>

<script setup>
import SettingsPage from '../../../components/settings/SettingsPage.vue'
import SaveBar from '../../../components/settings/SaveBar.vue'
import { useCompanySettings } from '../../../composables/useCompanySettings'

const { form, dirty, saving, error, save, reset } = useCompanySettings(['merchant_signup'])
</script>
