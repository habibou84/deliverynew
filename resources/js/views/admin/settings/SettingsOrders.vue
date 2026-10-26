<template>
  <SettingsPage icon="📦" title="Courses et livraison" intro="Comment les courses sont validées, quand la journée se termine et comment les livreurs travaillent.">
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

// Mêmes blocs que les panneaux ⚙️ des pages Courses, Planning et Livreurs
const KEYS = ['validation', 'day', 'couriers', 'autoPickup', 'autoDelivery', 'acceptance']
const { form, dirty, saving, error, save, reset } = useCompanySettings(fieldsOf(KEYS))
</script>
