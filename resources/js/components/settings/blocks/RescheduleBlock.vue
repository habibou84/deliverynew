<template>
  <SettingsBlock title="Colis non ramassés" :panel="panel">
    <label class="flex items-start gap-2 text-sm">
      <input v-model="form.reschedule_evening" type="checkbox" class="mt-1">
      <span>
        <b>Prévenir les e-commerçants le soir</b>
        <span class="block text-xs text-slate-600">Message aux e-commerçants dont les colis du jour n'ont pas été ramassés : ils répondent « tout demain », « 1 3 lundi », « 2 annuler »… La nouvelle date s'enregistre toute seule.</span>
      </span>
    </label>

    <template v-if="form.reschedule_evening">
      <div>
        <label class="label" :for="`rs-time${uid}`">Heure d'envoi</label>
        <input :id="`rs-time${uid}`" v-model="form.reschedule_evening_time" type="time" class="input w-32">
        <p class="mt-1 text-xs text-slate-600">Après l'heure limite des ramassages. Un bilan est envoyé au dispatch au même moment.</p>
      </div>

      <fieldset class="space-y-1">
        <legend class="label">Par où</legend>
        <label v-for="c in CHANNELS" :key="c.value" class="flex items-start gap-2 text-sm">
          <input v-model="form.reschedule_channel" type="radio" :value="c.value" class="mt-1">
          <span>{{ c.label }}</span>
        </label>
      </fieldset>
    </template>

    <fieldset class="space-y-1">
      <legend class="label">Sans réponse de l'e-commerçant</legend>
      <label v-for="n in NO_ANSWER" :key="n.value" class="flex items-start gap-2 text-sm">
        <input v-model="form.reschedule_no_answer" type="radio" :value="n.value" class="mt-1">
        <span>{{ n.label }}</span>
      </label>
    </fieldset>

    <p class="text-xs text-slate-600">
      L'e-commerçant peut reporter jusqu'à 14 jours ou annuler avec un motif. Seul son numéro WhatsApp enregistré peut répondre, pour ses colis ;
      une réponse incomprise ne change rien. En attendant, ces colis sont cachés chez les livreurs et listés dans « À reporter ».
    </p>
  </SettingsBlock>
</template>

<script setup>
import SettingsBlock from '../SettingsBlock.vue'
import { RESCHEDULE_CHANNELS as CHANNELS, RESCHEDULE_NO_ANSWER as NO_ANSWER } from '../../../views/admin/settings/options'

const props = defineProps({ form: { type: Object, required: true }, panel: Boolean })
const uid = props.panel ? '-panel' : ''
</script>
