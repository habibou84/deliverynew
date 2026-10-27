<template>
  <SettingsBlock title="Journée" :panel="panel">
    <div>
      <label class="label" :for="`cutoff${uid}`">Heure limite du jour</label>
      <input :id="`cutoff${uid}`" v-model="form.daily_cutoff_time" type="time" class="input w-32">
      <p class="text-xs text-gray-500 mt-1">Passé cette heure, les courses du jour encore sans livreur sont signalées et le dispatch est alerté. Vide = pas d'heure limite.</p>
      <MoreHelp>
        Les courses concernées sont signalées dans la liste et le tableau de bord, et les administrateurs et le dispatch reçoivent
        une alerte (une fois par jour). En validation avec règles, les nouvelles courses du jour arrivées après cette heure
        sont validées pour le lendemain.
      </MoreHelp>
      <details class="mt-2" :open="Object.keys(form.cutoff_by_day || {}).some((d) => form.cutoff_by_day[d])">
        <summary class="text-sm text-blue-600 cursor-pointer">Heure limite différente certains jours</summary>
        <div :class="['grid gap-2 mt-2', panel ? 'grid-cols-2' : 'grid-cols-2 sm:grid-cols-4']">
          <div v-for="(day, i) in WEEKDAYS" :key="day">
            <label class="label" :for="`cutoff${uid}-${i + 1}`">{{ day }}</label>
            <input :id="`cutoff${uid}-${i + 1}`" v-model="form.cutoff_by_day[i + 1]" type="time" class="input w-full">
          </div>
        </div>
        <p class="text-xs text-gray-500 mt-1">Un jour laissé vide suit l'heure limite commune.</p>
      </details>
    </div>
    <div>
      <label class="label" :for="`attempts${uid}`">Nombre maximal de tentatives de livraison</label>
      <input :id="`attempts${uid}`" v-model.number="form.default_max_attempts" type="number" min="1" max="10" class="input w-24">
    </div>
  </SettingsBlock>
</template>

<script setup>
import SettingsBlock from '../SettingsBlock.vue'
import MoreHelp from '../MoreHelp.vue'
import { WEEKDAYS } from '../../../views/admin/settings/options'

const props = defineProps({ form: { type: Object, required: true }, panel: Boolean })
// Identifiants distincts quand la page et son panneau affichent le même bloc
const uid = props.panel ? '-panel' : ''
</script>
