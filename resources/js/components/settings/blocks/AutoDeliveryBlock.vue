<template>
  <SettingsBlock title="Livraison du jour même automatique" :panel="panel">
    <p class="text-sm text-gray-600">
      Un colis récupéré ou au dépôt, à livrer aujourd'hui avant l'heure limite, part seul au livreur qui convient :
      celui qui a déjà le colis, puis le planning des livraisons, puis la zone principale avant la zone de secours,
      celui qui livre déjà dans le secteur, le moins chargé. Zones, capacité et fin de service se règlent sur la fiche de chaque livreur.
    </p>
    <div :class="['grid gap-2', panel ? '' : 'sm:grid-cols-3']" role="radiogroup" aria-label="Livraison automatique">
      <label v-for="m in DELIVERY_MODES" :key="m.value" :class="['rounded-lg border p-3 text-sm cursor-pointer', form.auto_delivery === m.value ? 'border-slate-900 ring-1 ring-slate-900' : 'border-slate-200']">
        <input v-model="form.auto_delivery" type="radio" :value="m.value" class="mr-1">
        <strong>{{ m.label }}</strong>
        <span class="block text-xs text-gray-500 mt-1">{{ m.help }}</span>
      </label>
    </div>
    <div v-if="form.auto_delivery !== 'off'" class="space-y-3">
      <div>
        <label class="label" :for="`max-cod${uid}`">Montant à encaisser au plus</label>
        <input :id="`max-cod${uid}`" v-model.number="form.auto_delivery_max_cod" type="number" min="0" step="500" class="input w-40" placeholder="Sans plafond">
        <p class="text-xs text-gray-500 mt-1">Au-dessus, l'équipe choisit elle-même le livreur. Vide = sans plafond.</p>
      </div>
      <label class="flex items-start gap-2 text-sm">
        <input v-model="form.same_courier_delivery" type="checkbox" class="mt-1">
        <span>Le livreur qui a fait le ramassage livre aussi, s'il dessert la zone et a de la place.</span>
      </label>
      <p class="text-xs text-gray-500">
        Pas de livraison automatique pour une expédition, un téléphone à vérifier, un retour demandé ou des tentatives épuisées.
      </p>
    </div>
  </SettingsBlock>
</template>

<script setup>
import SettingsBlock from '../SettingsBlock.vue'
import { DELIVERY_MODES } from '../../../views/admin/settings/options'

const props = defineProps({ form: { type: Object, required: true }, panel: Boolean })
const uid = props.panel ? '-panel' : ''
</script>
