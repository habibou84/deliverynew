<template>
  <SettingsBlock title="Caisse : argent gardé et clôture" :panel="panel">
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
    <h3 class="mt-4 text-sm font-semibold">Clôture de la caisse</h3>
    <div class="mt-1">
      <label class="label" :for="`cash-close${uid}`">Rappeler de clôturer la caisse à</label>
      <input :id="`cash-close${uid}`" v-model="form.cash_close_reminder_at" type="time" class="input w-32">
    </div>
    <label class="mt-2 flex items-center gap-2 text-sm"><input v-model="form.cash_close_summary" type="checkbox"> Envoyer le résumé de la clôture aux administrateurs par WhatsApp</label>
    <p class="text-xs text-gray-500 mt-1">
      Rappel dans l'application aux administrateurs et caissiers si la journée n'est pas clôturée (une fois par jour).
      Heure vide = pas de rappel. Le résumé arrive aussi dans la cloche.
    </p>
  </SettingsBlock>
</template>

<script setup>
import SettingsBlock from '../SettingsBlock.vue'

const props = defineProps({ form: { type: Object, required: true }, panel: Boolean })
const uid = props.panel ? '-panel' : ''
</script>
