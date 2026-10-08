<template>
  <form class="space-y-2 border-t pt-3" @submit.prevent="$emit('pay', { method, transaction_ref: reference || undefined })">
    <div class="grid grid-cols-2 gap-2">
      <div>
        <label class="label" for="pay-method">Payé par</label>
        <select id="pay-method" v-model="method" class="input">
          <option v-for="(label, value) in PAYMENT_METHODS" :key="value" :value="value">{{ label }}</option>
        </select>
      </div>
      <div>
        <label class="label" for="pay-ref">Référence</label>
        <input id="pay-ref" v-model="reference" class="input" placeholder="N° de transaction">
      </div>
    </div>
    <div class="flex gap-2">
      <button type="submit" class="btn-success flex-1">Marquer comme payé</button>
      <button type="button" class="btn-secondary" @click="$emit('cancel')">Annuler</button>
    </div>
  </form>
</template>

<script setup>
import { ref } from 'vue'
import { PAYMENT_METHODS } from '../utils/format'

defineEmits(['pay', 'cancel'])
const method = ref('wave')
const reference = ref('')
</script>
