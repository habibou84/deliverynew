<template>
  <SettingsPage icon="⚡" title="Automatisation" intro="Ce qui se fait tout seul pour les livraisons : validation des courses, journée, ramassages et livraisons confiés aux livreurs." back="/admin" back-label="Accueil">
    <RouterLink to="/admin/activite/planning" class="card flex items-center gap-3 p-4 hover:border-slate-400">
      <Icone nom="planning" class="text-slate-700" />
      <span class="min-w-0 flex-1">
        <span class="block font-semibold">Planning des livreurs</span>
        <span class="block text-sm text-slate-600">Qui ramasse et qui livre où et quand, les remplaçants, et le bouton pour suspendre l'automatisation.</span>
      </span>
      <span class="text-slate-600" aria-hidden="true">›</span>
    </RouterLink>
    <template v-if="form">
      <component :is="BLOCKS[key].component" v-for="key in KEYS" :key="key" :form="form" />
      <SaveBar :dirty="dirty" :saving="saving" :error="error" @save="save" @reset="reset" />
    </template>
  </SettingsPage>
</template>

<script setup>
import { RouterLink } from 'vue-router'
import SettingsPage from '../../../components/settings/SettingsPage.vue'
import SaveBar from '../../../components/settings/SaveBar.vue'
import { BLOCKS, fieldsOf } from '../../../components/settings/blocks'
import Icone from '../../../components/ui/Icone.vue'
import { useCompanySettings } from '../../../composables/useCompanySettings'

/**
 * Livraison › Automatisation : validation automatique, journée (heure limite, conseil d'ouverture ou de fermeture),
 * ramassage et livraison confiés automatiquement, acceptation des missions. Mêmes blocs que les panneaux ⚙️.
 */
const KEYS = ['validation', 'day', 'autoPickup', 'autoDelivery', 'acceptance']
const { form, dirty, saving, error, save, reset } = useCompanySettings(fieldsOf(KEYS))
</script>
