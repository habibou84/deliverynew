<template>
  <!-- Où livrer, comme dans Jibiat Marchand : un seul champ pour communes et quartiers ; à l'ouverture, les lieux les
       plus livrés avec leur prix ; en tapant, la partie tapée en gras -->
  <div class="relative">
    <div class="relative">
      <svg class="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
      <input
        :id="id"
        v-model="query"
        :class="['m-input !pl-12', invalid ? 'ring-2 ring-red-500' : '']"
        placeholder="Quartier ou commune (ex. Angré, Yop Siporex)"
        autocomplete="jibiat-off"
        role="combobox"
        :aria-expanded="open"
        :aria-controls="`${id}-list`"
        @focus="onFocus"
        @input="onInput"
        @blur="close"
        @keydown.enter.prevent="choose(rows[0]?.zone)"
      >
    </div>
    <div v-if="open && (rows.length || typed)" :id="`${id}-list`" class="absolute inset-x-0 top-full z-20 mt-2 overflow-hidden rounded-xl bg-white shadow-xl ring-1 ring-slate-200" role="listbox">
      <div class="max-h-80 overflow-y-auto">
        <p v-if="!typed" class="px-4 pb-1 pt-3 text-xs font-bold uppercase tracking-wide text-slate-500">{{ suggested.length ? 'Suggestions' : 'Communes et quartiers' }}</p>
        <button
          v-for="r in rows"
          :key="r.zone.id"
          type="button"
          role="option"
          :aria-selected="modelValue === r.zone.id"
          :class="['flex w-full items-center gap-3 px-4 py-3 text-left text-[15px] active:bg-emerald-50', modelValue === r.zone.id ? 'bg-emerald-50' : '']"
          @mousedown.prevent="choose(r.zone)"
        >
          <svg :class="['h-[18px] w-[18px] shrink-0', modelValue === r.zone.id ? 'text-[var(--app-color)]' : 'text-slate-400']" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21Z" /><circle cx="12" cy="9.5" r="2.5" /></svg>
          <span class="min-w-0 flex-1">{{ r.parts[0] }}<strong>{{ r.parts[1] }}</strong>{{ r.parts[2] }}<span v-if="!r.zone.parent_id" class="ml-1 text-xs text-slate-500">commune</span></span>
          <span v-if="fees[r.zone.id] != null" class="shrink-0 text-sm tabular-nums text-slate-500">{{ money(fees[r.zone.id]) }}</span>
        </button>
        <p v-if="typed && !rows.length" class="px-4 py-3 text-sm text-slate-600">Aucune commune ni aucun quartier ne correspond. Essayez un autre nom.</p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { money } from '../../utils/format'

const props = defineProps({
  modelValue: { type: Number, default: null },
  zones: { type: Array, default: () => [] },
  // Lieux proposés à l'ouverture (ceux du message, sinon les plus livrés), dans l'ordre
  suggested: { type: Array, default: () => [] },
  // Prix de la livraison par lieu, quand il est connu
  fees: { type: Object, default: () => ({}) },
  id: { type: String, default: 'zone' },
  invalid: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue'])

// « Cocody › Angré » s'écrit « Angré, Cocody » : le quartier d'abord
const label = (zone) => (zone?.full_name || '').split(' › ').reverse().join(', ')
const plain = (s) => (s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()

const selected = computed(() => props.zones.find((z) => z.id === props.modelValue))
const query = ref(label(selected.value))
const open = ref(false)
watch(selected, (zone) => { if (!open.value || zone) query.value = label(zone) })

const typed = computed(() => !!query.value.trim() && query.value !== label(selected.value))

function parts(text) {
  const term = plain(query.value.trim())
  const at = typed.value && term ? plain(text).indexOf(term) : -1
  return at < 0 ? [text, '', ''] : [text.slice(0, at), text.slice(at, at + term.length), text.slice(at + term.length)]
}

const rows = computed(() => {
  let list
  if (!typed.value) {
    list = props.suggested.length ? props.suggested : props.zones.slice(0, 8)
  } else {
    // « yop sipo » trouve « Siporex, Yopougon » : chaque mot tapé doit se retrouver dans le nom
    const words = plain(query.value).split(/[\s,›>]+/).filter(Boolean)
    const rank = (z) => (props.suggested.some((s) => s.id === z.id) ? 0 : plain(z.name).startsWith(words[0]) ? 1 : 2)
    list = props.zones
      .filter((z) => words.every((w) => plain(z.full_name).includes(w)))
      .sort((a, b) => rank(a) - rank(b) || label(a).localeCompare(label(b), 'fr'))
      .slice(0, 30)
  }

  return list.map((zone) => ({ zone, parts: parts(label(zone)) }))
})

function onFocus() {
  open.value = true
}

function onInput() {
  open.value = true
  if (props.modelValue) emit('update:modelValue', null)
}

function choose(zone) {
  if (!zone) return
  emit('update:modelValue', zone.id)
  query.value = label(zone)
  open.value = false
}

function close() {
  setTimeout(() => {
    open.value = false
    // Saisie abandonnée sans choix : le champ se vide, ou réaffiche le lieu choisi
    if (!props.modelValue) query.value = typed.value ? query.value : ''
    else query.value = label(selected.value)
  }, 150)
}
</script>
