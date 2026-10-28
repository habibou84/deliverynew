<template>
  <section v-if="look" class="card p-4 space-y-4">
    <div>
      <h2 class="font-semibold">Apparence des espaces</h2>
      <p class="text-sm text-gray-600">Couleurs de l'espace e-commerçant, de l'espace livreur et de l'espace équipe, nom et icône des applications installées sur les téléphones. Le logo ci-dessus s'affiche dans chaque espace.</p>
    </div>

    <!-- Couleur principale du logo appliquée à l'envoi du logo -->
    <div v-if="autoApplied" class="flex flex-wrap items-center gap-3 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900">
      <span class="flex-1">🎨 Couleurs prises dans votre logo : la palette « {{ autoApplied }} » est appliquée à l'espace e-commerçant.</span>
      <button type="button" class="btn-secondary text-xs" @click="undoAuto">Annuler</button>
    </div>

    <!-- Palettes proposées d'après le logo -->
    <div v-if="branding.logo_url" class="rounded-lg border p-3 space-y-3">
      <div class="flex flex-wrap items-start gap-3">
        <div class="flex-1 min-w-48">
          <h3 class="font-semibold">Couleurs de votre logo</h3>
          <p class="text-xs text-gray-500">Le fond, le blanc, le noir et les gris sont ignorés.</p>
        </div>
        <button v-if="!logo" type="button" class="btn-secondary text-xs" :disabled="loadingLogo" @click="loadLogoColors">
          {{ loadingLogo ? 'Analyse du logo…' : 'Proposer des couleurs depuis mon logo' }}
        </button>
      </div>

      <template v-if="logo">
        <p v-if="!logo.palettes.length" class="text-sm text-gray-600">
          Votre logo est en noir et blanc (ou en gris) : aucune couleur à proposer. Vos couleurs actuelles sont gardées ; choisissez-en une plus bas si vous voulez.
        </p>
        <template v-else>
          <div class="flex flex-wrap gap-3">
            <div v-for="c in logo.colors" :key="c.hex" class="flex flex-col items-center text-[11px] text-slate-600">
              <span class="h-9 w-9 rounded-lg border" :style="{ backgroundColor: c.hex }" />
              <span>{{ c.name }}</span>
              <span>{{ c.share }} %</span>
            </div>
          </div>
          <p class="text-xs text-gray-500">Chaque palette : couleur principale (barre, boutons), version foncée (textes), version claire (fonds). Le texte blanc reste toujours lisible.</p>
          <div class="grid gap-3 sm:grid-cols-3">
            <div v-for="p in logo.palettes" :key="p.key" :class="['rounded-lg border p-2 space-y-2', isApplied(p) ? 'border-emerald-500 ring-1 ring-emerald-500' : '']">
              <p class="flex items-center gap-2 text-sm font-semibold">
                {{ p.label }}
                <span v-if="isApplied(p)" class="rounded-full bg-emerald-100 px-2 text-[11px] font-semibold text-emerald-800">Appliquée</span>
              </p>
              <div class="grid grid-cols-3 gap-1">
                <span v-for="t in [p.color, p.dark, p.light]" :key="t" class="h-5 rounded border" :style="{ backgroundColor: t }" />
              </div>
              <p :class="['text-[11px]', p.adjusted ? 'text-amber-700' : 'text-emerald-700']">
                {{ p.adjusted ? `${p.from} du logo, assombri pour que le texte blanc reste lisible` : (p.from ? `${p.from} du logo · texte blanc lisible` : 'Plus calme · texte blanc lisible') }}
              </p>
              <!-- Aperçu : haut de l'accueil de l'e-commerçant -->
              <div class="rounded-lg overflow-hidden border bg-slate-50">
                <div class="px-2 py-1.5 text-white" :style="{ backgroundColor: p.color }">
                  <p class="text-[10px] opacity-90">Ma journée</p>
                  <p class="text-sm font-bold">8 courses</p>
                </div>
                <div class="p-2 space-y-1.5">
                  <span class="inline-block rounded-full px-2 text-[10px] font-semibold" :style="{ backgroundColor: p.light, color: p.dark }">Toutes 8</span>
                  <div class="rounded-md py-1 text-center text-[11px] font-semibold text-white" :style="{ backgroundColor: p.color }">+ Nouvelle course</div>
                </div>
              </div>
              <p v-if="isApplied(p)" class="rounded-md bg-emerald-50 py-1.5 text-center text-sm font-semibold text-emerald-800">Appliquée ✓</p>
              <button v-else type="button" class="btn-secondary w-full text-sm" :disabled="saving" @click="applyPalette(p)">Appliquer</button>
            </div>
          </div>
          <label v-if="accentPalette && !look.same" class="flex items-center gap-2 text-sm">
            <input v-model="courierAccent" type="checkbox">
            Espace livreur : l'autre couleur du logo, pour bien distinguer les deux applications
          </label>
        </template>
      </template>
    </div>

    <label class="flex items-center gap-2 text-sm font-medium">
      <input v-model="look.same" type="checkbox">
      Même apparence pour tous les espaces (couleur et icône de l'espace e-commerçant partout)
    </label>

    <div class="grid gap-4 md:grid-cols-3">
      <div v-for="s in SPACES" :key="s.key" :class="['rounded-lg border p-3 space-y-3', look.same && s.key !== 'marchand' ? 'opacity-60' : '']">
        <h3 class="font-semibold">{{ s.label }}</h3>

        <!-- Aperçu : barre du haut de l'espace et icône du téléphone -->
        <div class="rounded-lg overflow-hidden border">
          <div class="h-9 px-2 flex items-center gap-2 text-white text-sm font-semibold" :style="{ backgroundColor: colorOf(s.key) }">
            <img v-if="branding.logo_url" :src="branding.logo_url" alt="" class="h-6 w-6 rounded bg-white object-contain p-0.5">
            <span class="truncate">{{ s.preview }}</span>
          </div>
          <div class="p-2 bg-slate-50 flex items-center gap-3">
            <button type="button" class="rounded-md px-3 py-1 text-xs font-semibold text-white" :style="{ backgroundColor: colorOf(s.key) }">Bouton</button>
            <div v-if="s.app" class="flex flex-col items-center ml-auto">
              <img v-if="iconPreview(s.key)" :src="iconPreview(s.key)" alt="" class="h-11 w-11 rounded-xl object-cover shadow">
              <span v-else class="h-11 w-11 rounded-xl grid place-items-center text-white shadow" :style="{ backgroundColor: colorOf(s.key) }">{{ s.emoji }}</span>
              <span class="text-[10px] text-slate-700 mt-0.5 max-w-16 truncate">{{ look.spaces[s.key].app_name || look.spaces[s.key].default_app_name || s.defaultName }}</span>
            </div>
          </div>
        </div>

        <fieldset :disabled="look.same && s.key !== 'marchand'" class="space-y-3">
          <div>
            <p class="label">Couleur</p>
            <div class="flex flex-wrap items-center gap-1.5">
              <button
                v-for="c in PRESETS" :key="c" type="button"
                :class="['h-7 w-7 rounded-full ring-offset-2', look.spaces[s.key].color === c ? 'ring-2 ring-slate-900' : '']"
                :style="{ backgroundColor: c }" :aria-label="`Couleur ${c}`"
                @click="look.spaces[s.key].color = c"
              />
              <input v-model="look.spaces[s.key].color" type="color" class="h-7 w-9 cursor-pointer rounded border" :aria-label="`Autre couleur pour ${s.label}`">
            </div>
            <p v-if="tooLight(look.spaces[s.key].color)" class="mt-1 text-xs text-amber-700">⚠️ Couleur claire : le texte blanc sera difficile à lire. Choisissez une couleur plus foncée.</p>
          </div>
        </fieldset>

        <template v-if="s.app">
          <div>
            <label class="label" :for="`app-name-${s.key}`">Nom sous l'icône du téléphone</label>
            <input :id="`app-name-${s.key}`" v-model="look.spaces[s.key].app_name" class="input" maxlength="30" :placeholder="look.spaces[s.key].default_app_name || s.defaultName">
          </div>
          <fieldset :disabled="look.same && s.key !== 'marchand'" class="space-y-1">
            <p class="label">Icône de l'application</p>
            <p class="text-xs text-gray-500">{{ look.spaces[s.key].custom_icon ? 'Image carrée envoyée.' : (branding.logo_url ? 'Logo sur la couleur de l\'espace.' : 'Icône standard (envoyez un logo ou une image).') }}</p>
            <div class="flex flex-wrap gap-2">
              <label class="btn-secondary cursor-pointer text-xs">
                Envoyer une image carrée
                <input type="file" accept="image/png,image/jpeg,image/webp" class="sr-only" @change="uploadIcon(s.key, $event)">
              </label>
              <button v-if="look.spaces[s.key].custom_icon" type="button" class="btn-secondary text-xs text-red-700" @click="removeIcon(s.key)">Retirer</button>
            </div>
          </fieldset>
        </template>
      </div>
    </div>

    <p class="text-xs text-gray-500">
      Téléphones où l'application est déjà installée : le nom et l'icône se mettent à jour d'eux-mêmes sous 24 à 48 h sur Android ; sur iPhone, supprimez puis réinstallez l'application.
      Les applications Android téléchargées (APK) gardent leur icône, mais leurs écrans prennent la couleur et le logo.
    </p>
    <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
    <button type="button" class="btn-primary" :disabled="saving" @click="save()">{{ saving ? 'Enregistrement…' : 'Enregistrer l\'apparence' }}</button>
  </section>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import http, { apiErrorMessage } from '../bootstrap/axios'
import { branding, loadBranding } from '../composables/useBranding'
import { useToastStore } from '../stores/toasts'

/**
 * Paramètres › Apparence : couleur de chaque espace, nom et icône des applications installables.
 */
const SPACES = [
  { key: 'marchand', label: 'Espace e-commerçant', preview: 'Mes livraisons', app: true, defaultName: 'Nom de l\'entreprise', emoji: '📦' },
  { key: 'livreur', label: 'Espace livreur', preview: 'Mes missions', app: true, defaultName: 'Livreur', emoji: '🛵' },
  { key: 'admin', label: 'Espace équipe', preview: 'Administration', app: false },
]
const PRESETS = ['#047857', '#1d4ed8', '#0f172a', '#7c3aed', '#be123c', '#c2410c', '#0e7490', '#4d7c0f']

const toasts = useToastStore()
const look = ref(null)
const saving = ref(false)
const error = ref('')
// Couleurs repérées dans le logo et palettes proposées (GET /company/appearance/logo-colors, ou envoi du logo)
const logo = ref(null)
const loadingLogo = ref(false)
const autoApplied = ref('')
const courierAccent = ref(false)

const accentPalette = computed(() => logo.value?.palettes.find((p) => p.key === 'accent') ?? null)
const isApplied = (p) => look.value.spaces.marchand.color === p.color

const colorOf = (space) => (look.value.same ? look.value.spaces.marchand.color : look.value.spaces[space].color)
// Icône générée par le serveur (après enregistrement) ; sinon aperçu de la couleur
const iconPreview = (space) => (look.value.spaces[space].icon_url?.startsWith('/branding/') ? look.value.spaces[space].icon_url : null)

// Texte blanc lisible ? (luminance relative, seuil proche du contraste 3:1)
function tooLight(hex) {
  const [r, g, b] = [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16) / 255)
    .map((c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4))
  return 0.2126 * r + 0.7152 * g + 0.0722 * b > 0.3
}

function apply(data) {
  // Champ vide quand le nom par défaut s'applique (nom de l'entreprise pour l'e-commerçant) : il suit
  // alors un changement de nom de l'entreprise au lieu d'être enregistré tel quel
  for (const s of SPACES.filter((x) => x.app)) {
    const space = data.spaces[s.key]
    if (space.app_name === space.default_app_name) space.app_name = ''
  }
  look.value = data
}

async function save(colors = {}, message = 'Apparence enregistrée.') {
  saving.value = true
  error.value = ''
  try {
    const spaces = Object.fromEntries(SPACES.map((s) => [s.key, {
      color: s.key in colors ? colors[s.key] : look.value.spaces[s.key].color,
      app_name: look.value.spaces[s.key].app_name || null,
    }]))
    apply((await http.put('/company/appearance', { same: look.value.same, spaces })).data.data)
    await loadBranding(true)
    toasts.success(message)
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    saving.value = false
  }
}

async function loadLogoColors() {
  loadingLogo.value = true
  try {
    logo.value = (await http.get('/company/appearance/logo-colors')).data.data
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    loadingLogo.value = false
  }
}

// Palette choisie : couleur de l'espace e-commerçant (et l'autre couleur du logo pour l'espace livreur si demandé)
async function applyPalette(p) {
  const colors = { marchand: p.color }
  if (courierAccent.value && !look.value.same && accentPalette.value) {
    colors.livreur = p.key === 'accent' ? logo.value.palettes[0].color : accentPalette.value.color
  }
  autoApplied.value = ''
  await save(colors, `Palette « ${p.label} » appliquée.`)
}

// Annuler la couleur prise dans le logo : retour à la couleur par défaut de l'espace e-commerçant
async function undoAuto() {
  autoApplied.value = ''
  await save({ marchand: null }, 'Couleur par défaut remise.')
}

/** Appelé après l'envoi du logo (Paramètres › Entreprise et marque) */
function fromLogo(data) {
  logo.value = { colors: data.colors ?? [], palettes: data.palettes ?? [] }
  if (data.appearance) apply(data.appearance)
  autoApplied.value = data.applied ? (logo.value.palettes.find((p) => p.key === data.applied)?.label ?? '') : ''
}

defineExpose({ fromLogo, forgetLogo: () => { logo.value = null; autoApplied.value = '' } })

async function uploadIcon(space, event) {
  const file = event.target.files[0]
  event.target.value = ''
  if (!file) return
  try {
    const body = new FormData()
    body.append('icon', file)
    apply((await http.post(`/company/appearance/icons/${space}`, body)).data.data)
    loadBranding(true)
    toasts.success('Icône enregistrée.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function removeIcon(space) {
  apply((await http.delete(`/company/appearance/icons/${space}`)).data.data)
  loadBranding(true)
}

onMounted(async () => {
  apply((await http.get('/company/appearance')).data.data)
})
</script>
