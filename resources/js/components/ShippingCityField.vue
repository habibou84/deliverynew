<template>
  <!-- Expédition : ville où le transporteur emmène le colis (liste des villes, ou une autre tapée à la main) -->
  <div>
    <label :class="mobile ? 'm-label' : 'label'" :for="id">Ville de destination{{ required ? ' *' : '' }}</label>
    <input
      :id="id" :value="modelValue" :list="`${id}-villes`" :required="required" maxlength="100" autocomplete="off"
      :class="inputClass || (mobile ? 'm-input' : 'input')" placeholder="Ex. : Bouaké"
      @input="$emit('update:modelValue', $event.target.value)"
    >
    <datalist :id="`${id}-villes`">
      <option v-for="c in cities" :key="c" :value="c" />
    </datalist>
    <p :class="['mt-1 text-xs', mobile ? 'text-slate-600' : 'text-slate-600']">Le livreur dépose le colis à la gare ; les frais du transporteur sont déduits du point de l'e-commerçant.</p>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import http from '../bootstrap/axios'

defineProps({
  modelValue: { type: String, default: '' },
  id: { type: String, default: 'shipping-city' },
  required: { type: Boolean, default: false },
  mobile: { type: Boolean, default: false },
  inputClass: { type: [String, Array], default: null },
})
defineEmits(['update:modelValue'])

// Liste chargée une fois pour toute l'application
let cache = null
const cities = ref([])
onMounted(async () => {
  cache ??= http.get('/shipping-cities').then(({ data }) => data.data).catch(() => [])
  cities.value = await cache
})
</script>
