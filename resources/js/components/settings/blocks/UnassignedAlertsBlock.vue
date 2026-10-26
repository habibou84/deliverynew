<template>
  <SettingsBlock title="Courses sans livreur" :panel="panel">
    <p v-if="panel" class="text-xs text-gray-500">0 = pas d'alerte.</p>
    <div :class="['grid gap-3', panel ? '' : 'sm:grid-cols-2']">
      <div>
        <label class="label" :for="`pickup-alert${uid}`">Sans livreur de ramassage depuis plus de (minutes)</label>
        <input :id="`pickup-alert${uid}`" v-model.number="form.pickup_assign_alert_minutes" type="number" min="0" max="1440" class="input w-24">
      </div>
      <div>
        <label class="label" :for="`delivery-alert${uid}`">Colis récupéré sans livreur de livraison depuis plus de (minutes)</label>
        <input :id="`delivery-alert${uid}`" v-model.number="form.delivery_assign_alert_minutes" type="number" min="0" max="1440" class="input w-24">
      </div>
    </div>
    <MoreHelp>
      Au-delà, la course passe en rouge dans la liste, le compteur du menu « Courses » augmente et le dispatch reçoit une alerte sonore
      (une fois par course) ; les administrateurs sont prévenus après trois fois ce délai. Une livraison prévue un autre jour ne compte
      qu'à partir de ce jour.
    </MoreHelp>
  </SettingsBlock>
</template>

<script setup>
import SettingsBlock from '../SettingsBlock.vue'
import MoreHelp from '../MoreHelp.vue'

const props = defineProps({ form: { type: Object, required: true }, panel: Boolean })
const uid = props.panel ? '-panel' : ''
</script>
