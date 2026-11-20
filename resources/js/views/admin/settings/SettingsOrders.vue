<template>
  <SettingsPage icon="📦" title="Courses et livreurs" intro="Les courses pas ramassées à leur date et le travail des livreurs sur le terrain. La validation, la journée et l'automatisation sont dans Livraison › Automatisation.">
    <template v-if="form">
      <component :is="BLOCKS[key].component" v-for="key in KEYS" :key="key" :form="form" />
      <SaveBar :dirty="dirty" :saving="saving" :error="error" @save="save" @reset="reset" />
    </template>
  </SettingsPage>
</template>

<script setup>
import SettingsPage from '../../../components/settings/SettingsPage.vue'
import SaveBar from '../../../components/settings/SaveBar.vue'
import { BLOCKS, fieldsOf } from '../../../components/settings/blocks'
import { useCompanySettings } from '../../../composables/useCompanySettings'

// Mêmes blocs que les panneaux ⚙️ des pages Courses et Livreurs (automatisation : page Automatisation ;
// modifications des e-commerçants : Réglages clients)
const KEYS = ['reschedule', 'couriers']
const { form, dirty, saving, error, save, reset } = useCompanySettings(fieldsOf(KEYS))
</script>
