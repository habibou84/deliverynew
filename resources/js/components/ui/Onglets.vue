<template>
  <!-- Téléphone : une ligne qui défile ; ordinateur : les étapes passent à la ligne d'un seul bloc si elles sont nombreuses -->
  <nav class="flex gap-x-1 overflow-x-auto no-scrollbar border-b border-slate-200 lg:flex-wrap lg:overflow-visible" :aria-label="label">
    <div v-for="(block, b) in blocks" :key="block.group || b" class="flex shrink-0 items-stretch">
      <!-- Titre de l'étape (Ramassage, Livraison…), séparé de l'étape précédente -->
      <span
        v-if="block.group"
        :class="['flex shrink-0 items-center pr-1 text-xs font-semibold uppercase tracking-wide text-slate-600', b ? 'ml-1 border-l border-slate-300 pl-3' : 'pl-1']"
      >{{ block.group }}</span>
      <component
        :is="o.to ? RouterLink : 'button'"
        v-for="o in block.items"
        :key="o.value ?? o.to"
        :to="o.to"
        :type="o.to ? undefined : 'button'"
        :class="[
          '-mb-px inline-flex min-h-11 shrink-0 items-center gap-2 whitespace-nowrap border-b-[3px] px-3 text-sm',
          isActive(o) ? 'border-[var(--admin-color,#0f172a)] font-semibold text-slate-900' : 'border-transparent font-medium text-slate-600 hover:text-slate-900',
        ]"
        :aria-current="isActive(o) ? 'page' : undefined"
        @click="o.to ? null : emit('update:modelValue', o.value)"
      >
        {{ o.label }}
        <span
          v-if="o.count"
          :class="['grid h-[22px] min-w-[22px] place-items-center rounded-full px-1.5 text-xs font-semibold tabular-nums', o.urgent ? 'bg-red-600 text-white' : 'bg-slate-100 text-slate-700']"
          :title="o.countTitle"
        >{{ o.count > 99 ? '99+' : o.count }}</span>
        <span
          v-if="o.late"
          class="grid h-[22px] min-w-[22px] place-items-center rounded-full bg-red-600 px-1.5 text-xs font-semibold text-white tabular-nums"
          :title="o.lateTitle"
        >{{ o.late }}<span class="sr-only"> {{ o.lateTitle }}</span></span>
      </component>
    </div>
  </nav>
</template>

<script setup>
import { computed } from 'vue'
import { RouterLink, useRoute } from 'vue-router'

/**
 * Onglets uniques de l'administration (charte, composant Onglets).
 * items : [{ value, label, group?, count?, urgent?, late?, lateTitle?, to?, exact? }] ; group : étape (titre affiché devant son premier onglet) ; avec `to`, l'onglet est un lien
 * (actif selon l'adresse), sinon un bouton qui émet update:modelValue.
 */
const props = defineProps({
  items: { type: Array, required: true },
  modelValue: { type: [String, Number], default: null },
  label: { type: String, default: 'Onglets' },
})
const emit = defineEmits(['update:modelValue'])
const route = useRoute()

// Onglets regroupés par étape (éléments consécutifs de même group) : chaque étape passe à la ligne d'un bloc
const blocks = computed(() => props.items.reduce((list, o) => {
  const last = list[list.length - 1]
  if (last && last.group === (o.group || '')) last.items.push(o)
  else list.push({ group: o.group || '', items: [o] })
  return list
}, []))

function isActive(o) {
  if (!o.to) return o.value === props.modelValue
  return o.exact ? route.path === o.to || route.path === `${o.to}/` : route.path.startsWith(o.to)
}
</script>
