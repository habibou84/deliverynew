<template>
  <SettingsPage icon="📥" title="Reprise de l'ancienne application" intro="Récupérer les comptes de l'ancienne application, puis y faire basculer tout le monde le jour choisi.">
    <LegacyImportSettings />
    <LegacySwitchSettings />
  </SettingsPage>
</template>

<script setup>
import SettingsPage from '../../../components/settings/SettingsPage.vue'
import LegacyImportSettings from '../../../components/LegacyImportSettings.vue'
import LegacySwitchSettings from '../../../components/LegacySwitchSettings.vue'
</script>
