<template>
  <SettingsBlock title="Livreurs" :panel="panel">
    <label class="flex items-start gap-2 text-sm">
      <input v-model="form.require_delivery_code" type="checkbox" class="mt-1">
      <span>Exiger le code de livraison du destinataire<span class="block text-gray-500">Le destinataire reçoit son code sur WhatsApp quand le livreur part livrer, ou par le marchand.</span></span>
    </label>
    <label class="flex items-start gap-2 text-sm">
      <input v-model="form.offline_courier_actions" type="checkbox" class="mt-1">
      <span>Mode hors connexion des livreurs
        <span class="block text-gray-500">Sans réseau, le livreur enregistre quand même ses étapes ; l'application les envoie au retour du réseau.</span>
      </span>
    </label>
    <MoreHelp>
      Hors connexion, le livreur peut enregistrer « récupéré », « en route », « livré » (avec l'encaissement), un échec, un report ou une note,
      à l'heure où ils ont été faits. Une livraison avec un code client faux est gardée mais signalée (file « Codes à vérifier »).
      Les WhatsApp au client et au marchand partent au retour du réseau.
    </MoreHelp>
    <div>
      <p class="label">Après une mission ramassage, les colis…</p>
      <div :class="['grid gap-2', panel ? '' : 'sm:grid-cols-3']">
        <label v-for="m in CUSTODY_MODES" :key="m.value" :class="['rounded-lg border p-3 text-sm cursor-pointer', form.pickup_mission_custody === m.value ? 'border-slate-900 ring-1 ring-slate-900' : 'border-slate-200']">
          <input v-model="form.pickup_mission_custody" type="radio" :value="m.value" class="mr-1">
          <strong>{{ m.label }}</strong>
          <span class="block text-xs text-gray-500 mt-1">{{ m.help }}</span>
        </label>
      </div>
    </div>
    <div v-if="form.pickup_mission_custody !== 'keep'">
      <label class="label" :for="`depot-closes${uid}`">Heure de fermeture du dépôt</label>
      <input :id="`depot-closes${uid}`" v-model="form.depot_closes_at" type="time" class="input w-32">
      <p class="text-xs text-gray-500 mt-1">Passé cette heure, le livreur garde les colis d'une mission ramassage jusqu'à leur livraison. Vide = dépôt toujours ouvert.</p>
    </div>
  </SettingsBlock>
</template>

<script setup>
import SettingsBlock from '../SettingsBlock.vue'
import MoreHelp from '../MoreHelp.vue'
import { CUSTODY_MODES } from '../../../views/admin/settings/options'

const props = defineProps({ form: { type: Object, required: true }, panel: Boolean })
const uid = props.panel ? '-panel' : ''
</script>
