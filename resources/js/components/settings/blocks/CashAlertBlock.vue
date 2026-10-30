<template>
  <SettingsBlock title="Argent gardé par un livreur" :panel="panel">
    <div class="flex flex-wrap gap-4">
      <div>
        <label class="label" :for="`cash-hours${uid}`">Argent gardé depuis plus de (heures)</label>
        <input :id="`cash-hours${uid}`" v-model.number="form.cash_alert_hours" type="number" min="0" max="336" class="input w-24">
      </div>
      <div>
        <label class="label" :for="`cash-amount${uid}`">ou au-dessus de (F)</label>
        <input :id="`cash-amount${uid}`" v-model.number="form.cash_alert_amount" type="number" min="0" class="input w-36" placeholder="Pas de plafond">
      </div>
    </div>
    <label class="mt-2 flex items-center gap-2 text-sm"><input v-model="form.cash_alert_courier" type="checkbox"> Rappel au livreur par WhatsApp</label>
    <p class="text-xs text-gray-500 mt-1">
      Alerte aux administrateurs et caissiers dans l'application, une fois par livreur et par jour ; le livreur apparaît
      « à surveiller » dans la caisse. 0 heure et plafond vide = pas d'alerte.
    </p>
  </SettingsBlock>
</template>

<script setup>
import SettingsBlock from '../SettingsBlock.vue'

const props = defineProps({ form: { type: Object, required: true }, panel: Boolean })
const uid = props.panel ? '-panel' : ''
</script>
