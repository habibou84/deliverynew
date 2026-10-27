<template>
  <SettingsPage icon="⏰" title="Alertes et rappels" intro="Quand prévenir le dispatch et les administrateurs. 0 = pas d'alerte.">
    <template v-if="form">
      <UnassignedAlertsBlock :form="form" />
      <HoldAlertBlock :form="form" />
      <FieldReminderBlock :form="form" />
      <WeeklyReportBlock :form="form" />
      <SaveBar :dirty="dirty" :saving="saving" :error="error" @save="save" @reset="reset" />
    </template>
  </SettingsPage>
</template>

<script setup>
import SettingsPage from '../../../components/settings/SettingsPage.vue'
import SaveBar from '../../../components/settings/SaveBar.vue'
import { fieldsOf } from '../../../components/settings/blocks'
import FieldReminderBlock from '../../../components/settings/blocks/FieldReminderBlock.vue'
import HoldAlertBlock from '../../../components/settings/blocks/HoldAlertBlock.vue'
import UnassignedAlertsBlock from '../../../components/settings/blocks/UnassignedAlertsBlock.vue'
import WeeklyReportBlock from '../../../components/settings/blocks/WeeklyReportBlock.vue'
import { useCompanySettings } from '../../../composables/useCompanySettings'

// Mêmes blocs que les panneaux ⚙️ des pages Livreurs disponibles, Colis chez les livreurs, Remontées terrain et Statistiques
const { form, dirty, saving, error, save, reset } = useCompanySettings(fieldsOf(['unassignedAlerts', 'holdAlert', 'fieldReminder', 'weeklyReport']))
</script>
