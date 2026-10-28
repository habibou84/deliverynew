<template>
  <SettingsPage icon="📥" title="Reprise de l'ancienne application" intro="Récupérer les comptes et les commandes de l'ancienne application, les garder à jour chaque minute, puis y faire basculer tout le monde le jour choisi.">
    <LegacyImportSettings />
    <LegacyOrdersSettings />
    <LegacySwitchSettings />
  </SettingsPage>
</template>

<script setup>
import SettingsPage from '../../../components/settings/SettingsPage.vue'
import LegacyImportSettings from '../../../components/LegacyImportSettings.vue'
import LegacyOrdersSettings from '../../../components/LegacyOrdersSettings.vue'
import LegacySwitchSettings from '../../../components/LegacySwitchSettings.vue'
</script>
