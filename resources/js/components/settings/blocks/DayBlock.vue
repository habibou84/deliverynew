<template>
  <SettingsBlock title="Journée" :panel="panel">
    <div>
      <label class="label" :for="`cutoff${uid}`">Heure limite du jour</label>
      <input :id="`cutoff${uid}`" v-model="form.daily_cutoff_time" type="time" class="input w-32">
      <p class="text-xs text-slate-600 mt-1">Passé cette heure, les courses du jour encore sans livreur sont signalées et le dispatch est alerté. Vide = pas d'heure limite.</p>
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
        <p class="text-xs text-slate-600 mt-1">Un jour laissé vide suit l'heure limite commune.</p>
      </details>
    </div>
    <!-- Conseil du Poste de pilotage : rouvrir ou fermer la journée selon les livreurs libres -->
    <div v-if="form.day_advice">
      <label class="flex items-start gap-2">
        <input v-model="form.day_advice.enabled" type="checkbox" class="mt-1">
        <span>
          <span class="font-medium">Conseiller d'ouvrir ou de fermer la journée</span>
          <span class="block text-xs text-slate-600">Sur le Poste de pilotage : rouvrir quand des livreurs libres peuvent encore livrer, fermer quand trop de courses du jour attendent un livreur. Rien ne se fait sans votre clic.</span>
        </span>
      </label>
      <div v-if="form.day_advice.enabled" :class="['grid gap-3 mt-3', panel ? 'grid-cols-1' : 'sm:grid-cols-3']">
        <div>
          <label class="label" :for="`advice-couriers${uid}`">Livreurs libres au minimum</label>
          <input :id="`advice-couriers${uid}`" v-model.number="form.day_advice.min_couriers" type="number" min="1" max="50" class="input w-24">
          <p class="text-xs text-slate-600 mt-1">pour conseiller de rouvrir</p>
        </div>
        <div>
          <label class="label" :for="`advice-seen${uid}`">Vu dans l'application depuis</label>
          <div class="flex items-center gap-2"><input :id="`advice-seen${uid}`" v-model.number="form.day_advice.seen_minutes" type="number" min="5" max="240" class="input w-24"> <span class="text-sm">min</span></div>
          <p class="text-xs text-slate-600 mt-1">au plus, pour compter un livreur comme libre</p>
        </div>
        <div>
          <label class="label" :for="`advice-margin${uid}`">Marge avant la fermeture du dépôt</label>
          <div class="flex items-center gap-2"><input :id="`advice-margin${uid}`" v-model.number="form.day_advice.margin_minutes" type="number" min="30" max="300" class="input w-24"> <span class="text-sm">min</span></div>
          <p class="text-xs text-slate-600 mt-1">on rouvre jusqu'à cette heure-là au plus tard</p>
        </div>
      </div>
      <MoreHelp>
        Un livreur compte comme libre s'il est marqué disponible, a ouvert l'application récemment et a encore de la place
        (colis, argent, temps avant la fermeture du dépôt). Rouvrir repousse l'heure limite pour aujourd'hui seulement.
        Un conseil écarté (« Pas aujourd'hui », « Garder ouvert ») ne revient pas avant le lendemain.
      </MoreHelp>
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
