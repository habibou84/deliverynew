<template>
  <SettingsBlock title="Annuaire des entreprises de livraison" :panel="panel">
    <p class="text-sm text-gray-600">
      Les e-commerçants de Jibiat Marchand et les visiteurs de jibiat.com/annuaire vous trouvent, voient vos villes, votre prix de départ
      et vos vrais résultats des 90 derniers jours (livraisons réussies, délai moyen), puis se connectent à vous en un clic.
    </p>
    <label class="flex items-start gap-2 text-sm">
      <input v-model="form.directory_listed" type="checkbox" class="mt-1">
      <span>Figurer dans l'annuaire<span class="block text-gray-500">Vos chiffres n'apparaissent qu'à partir de 20 courses terminées. Vous pouvez vous retirer à tout moment.</span></span>
    </label>
    <div v-if="form.directory_listed">
      <label class="label" :for="`directory-pitch${uid}`">Votre présentation en une phrase</label>
      <textarea :id="`directory-pitch${uid}`" v-model="form.directory_pitch" class="input" rows="2" maxlength="240" placeholder="Ex. : Livraison le jour même dans tout Abidjan, reversement chaque soir par Wave." />
      <p class="text-xs text-gray-500 mt-1">{{ (form.directory_pitch || '').length }} / 240 caractères. Dites ce qui vous distingue : zones, rapidité, reversements, expédition à l'intérieur du pays.</p>
    </div>
  </SettingsBlock>
</template>

<script setup>
import SettingsBlock from '../SettingsBlock.vue'

const props = defineProps({ form: { type: Object, required: true }, panel: Boolean })
const uid = props.panel ? '-panel' : ''
</script>
