<template>
  <SettingsBlock title="Acceptation des missions" :panel="panel">
    <p class="text-sm text-gray-600">
      Faut-il que le livreur accepte chaque mission ? Les missions du planning sont toujours acceptées d'office.
      Une règle différente peut être donnée à un livreur depuis sa fiche.
    </p>
    <div :class="['grid gap-2', panel ? '' : 'sm:grid-cols-3']" role="radiogroup" aria-label="Acceptation des missions">
      <label v-for="m in ACCEPT_MODES" :key="m.value" :class="['rounded-lg border p-3 text-sm cursor-pointer', form.mission_acceptance === m.value ? 'border-slate-900 ring-1 ring-slate-900' : 'border-slate-200']">
        <input v-model="form.mission_acceptance" type="radio" :value="m.value" class="mr-1">
        <strong>{{ m.label }}</strong>
        <span class="block text-xs text-gray-500 mt-1">{{ m.help }}</span>
      </label>
    </div>
    <div :class="['grid gap-3', panel ? '' : 'sm:grid-cols-2']">
      <div>
        <label class="label" :for="`unreachable${uid}`">Livreur injoignable après (minutes)</label>
        <input :id="`unreachable${uid}`" v-model.number="form.unreachable_minutes" type="number" min="0" max="240" class="input w-32">
        <p class="text-xs text-gray-500 mt-1">Sans aucun signe de son application, ses ramassages du planning pas encore commencés passent à son remplaçant, et l'équipe est prévenue. 0 = jamais.</p>
      </div>
      <div>
        <label class="label" :for="`late-pickup${uid}`">Ramassage pas commencé : alerte (minutes avant l'heure limite)</label>
        <input :id="`late-pickup${uid}`" v-model.number="form.late_pickup_alert_minutes" type="number" min="0" max="600" class="input w-32">
        <p class="text-xs text-gray-500 mt-1">
          L'équipe est prévenue des ramassages du jour pas encore commencés ; rien n'est retiré au livreur. 0 = jamais.
          <span v-if="'daily_cutoff_time' in form && !form.daily_cutoff_time" class="text-amber-700">Indiquez d'abord l'heure limite du jour.</span>
        </p>
      </div>
    </div>
    <label class="flex items-start gap-2 text-sm">
      <input v-model="form.courier_available_alert" type="checkbox" class="mt-1">
      <span>
        Prévenir l'équipe quand un livreur est libre avec des ramassages près de lui
        <span class="block text-xs text-gray-500">Une alerte par livreur et par jour, pour lui confier une mission ramassage.</span>
      </span>
    </label>
  </SettingsBlock>
</template>

<script setup>
import SettingsBlock from '../SettingsBlock.vue'
import { ACCEPT_MODES } from '../../../views/admin/settings/options'

const props = defineProps({ form: { type: Object, required: true }, panel: Boolean })
const uid = props.panel ? '-panel' : ''
</script>
