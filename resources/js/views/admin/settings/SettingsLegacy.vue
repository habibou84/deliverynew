<template>
  <SettingsPage icon="📥" title="Reprise de l'ancienne application" intro="Récupérer les comptes et les commandes de l'ancienne application, les garder à jour chaque minute, puis y faire basculer tout le monde le jour choisi.">
    <LegacyImportSettings />
    <LegacyOrdersSettings />
    <LegacyRecheckSettings />
    <LegacyPurgeSettings />
    <section class="card p-4 flex flex-wrap items-center gap-3">
      <div class="flex-1 min-w-[16rem]">
        <h2 class="font-semibold">Argent chez les livreurs : point de départ</h2>
        <p class="text-sm text-gray-600">L'ancienne application n'enregistrait pas toujours l'argent remis par les livreurs. Faites le point avec chacun : avant la date choisie, l'argent est compté comme remis.</p>
      </div>
      <RouterLink to="/admin/reglages/reprise/argent-livreurs" class="btn-secondary">Faire le point ›</RouterLink>
    </section>
    <LegacySwitchSettings />
  </SettingsPage>
</template>

<script setup>
import { RouterLink } from 'vue-router'
import SettingsPage from '../../../components/settings/SettingsPage.vue'
import LegacyImportSettings from '../../../components/LegacyImportSettings.vue'
import LegacyOrdersSettings from '../../../components/LegacyOrdersSettings.vue'
import LegacyPurgeSettings from '../../../components/LegacyPurgeSettings.vue'
import LegacyRecheckSettings from '../../../components/LegacyRecheckSettings.vue'
import LegacySwitchSettings from '../../../components/LegacySwitchSettings.vue'
</script>
