<template>
  <div class="max-w-4xl space-y-4">
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-xl font-bold">⚙️ Réglages</h1>
        <p class="text-sm text-gray-600">Tous les réglages de l'entreprise, rangés par thème.</p>
      </div>
      <input v-model="query" type="search" class="input sm:w-72" placeholder="Chercher un réglage (heure limite, logo…)" aria-label="Chercher un réglage">
    </div>

    <!-- Parcours de mise en route -->
    <RouterLink v-if="setup && !query" to="/admin/mise-en-route" :class="['card p-4 flex items-center gap-3 hover:border-slate-400 transition', setup.ready && !setup.health.length ? '' : 'border-amber-300 bg-amber-50']">
      <span class="text-2xl leading-none" aria-hidden="true">🧭</span>
      <span class="min-w-0 flex-1">
        <span class="block font-semibold">Mise en route</span>
        <span class="block text-sm text-gray-600">
          {{ setup.ready ? 'Étapes essentielles faites' : `Étapes essentielles : ${setup.essentials.done} sur ${setup.essentials.total}` }}
          · conseillées : {{ setup.recommended.done }} sur {{ setup.recommended.total }}
        </span>
        <span v-if="setup.health.length" class="block text-sm font-medium text-red-700">Bilan de santé : {{ setup.health.length }} point(s) à corriger</span>
      </span>
      <span class="text-sm font-medium text-blue-600 whitespace-nowrap">{{ setup.ready ? 'Revoir' : 'Continuer' }} →</span>
    </RouterLink>

    <!-- Les réglages en vidéo -->
    <GuideVideo
      v-if="!query" compact name="reglages" title="Bien démarrer : les réglages" duration="2 min"
      text="Où trouver chaque réglage et comment le faire, en vidéo."
    />

    <p v-if="!cards.length" class="card p-6 text-center text-gray-500">Aucun réglage ne correspond à « {{ query }} ».</p>

    <div class="grid gap-3 sm:grid-cols-2">
      <component
        :is="card.to ? 'RouterLink' : 'div'"
        v-for="card in cards"
        :key="card.key"
        :to="card.to"
        :class="['card p-4 flex gap-3', card.to ? 'hover:border-slate-400 transition' : '']"
      >
        <span class="text-2xl leading-none" aria-hidden="true">{{ card.icon }}</span>
        <span class="min-w-0 flex-1 space-y-1">
          <span class="block font-semibold">{{ card.title }}</span>
          <span class="block text-sm text-gray-600">{{ card.description }}</span>
          <span v-if="card.summary" class="block text-xs font-medium text-slate-700">{{ card.summary }}</span>
          <!-- Aussi réglable depuis ces pages, par leur bouton ⚙️ -->
          <span v-if="card.also" class="flex flex-wrap gap-1 pt-1">
            <button v-for="a in card.also" :key="a.to" type="button" class="rounded-full bg-sky-50 px-2 py-0.5 text-xs text-sky-800 hover:bg-sky-100" @click.prevent.stop="router.push(a.to)">aussi dans : {{ a.label }}</button>
          </span>
          <span v-if="card.links" class="flex flex-wrap gap-x-3 gap-y-1 pt-1">
            <RouterLink v-for="l in card.links" :key="l.to" :to="l.to" class="text-sm font-medium text-blue-600">{{ l.label }} →</RouterLink>
          </span>
        </span>
      </component>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import http from '../../../bootstrap/axios'
import GuideVideo from '../../../components/GuideVideo.vue'
import { useAuthStore } from '../../../stores/auth'
import { ACCEPT_MODES, AUTO_MODES, DELIVERY_MODES, delay, VALIDATION_MODES } from './options'

/**
 * Page d'entrée des réglages : une carte par thème, avec l'essentiel de l'état actuel, et une recherche.
 * Une carte ou un lien sans le droit ou le module correspondant n'apparaît pas.
 */
const auth = useAuthStore()
const router = useRouter()
const company = ref(null)
const query = ref('')
const setup = ref(null)

const allowed = (item) => (!item.permission || auth.can(item.permission)) && (!item.feature || auth.hasFeature(item.feature))
const onOff = (v) => (v ? 'oui' : 'non')

const ALL = computed(() => {
  const c = company.value
  const mode = VALIDATION_MODES.find((m) => m.value === c?.validation_mode)
  return [
    {
      key: 'company', icon: '🏢', title: 'Entreprise et marque', to: '/admin/reglages/entreprise', permission: 'settings.manage',
      description: 'Nom, coordonnées, logo, couleurs et icônes des applications, adresse de l\'espace.',
      summary: c && `${c.name}${c.logo_url ? ' · logo en place' : ' · sans logo'}`,
      keywords: 'nom téléphone adresse accroche logo couleurs apparence icône application domaine',
    },
    {
      key: 'orders', icon: '📦', title: 'Courses et livraison', to: '/admin/reglages/courses', permission: 'settings.manage',
      also: [{ to: '/admin/courses', label: 'Courses' }, { to: '/admin/planning', label: 'Planning' }, { to: '/admin/livreurs', label: 'Livreurs' }],
      description: 'Validation des courses, heure limite, tentatives, code de livraison, hors connexion, garde ou dépôt.',
      summary: c && `Validation ${mode?.short || '—'} · heure limite ${c.daily_cutoff_time?.slice(0, 5) || 'aucune'} · ${c.default_max_attempts} tentatives · ramassage automatique ${AUTO_MODES.find((m) => m.value === c.auto_pickup)?.short || 'désactivé'} · livraison automatique ${DELIVERY_MODES.find((m) => m.value === c.auto_delivery)?.short || 'désactivée'} · missions ${ACCEPT_MODES.find((m) => m.value === c.mission_acceptance)?.short || 'au choix du livreur'}`,
      keywords: 'validation heure limite journée tentatives code livraison hors connexion offline garde dépôt fermeture assignation automatique planning acceptation missions refus empêchement injoignable retard livraison jour même plafond',
    },
    {
      key: 'pricing', icon: '🗺️', title: 'Zones et tarifs', permission: 'settings.manage',
      description: 'Zones desservies, grilles de prix, suppléments, frais des colis retournés.',
      summary: c && `Frais d'un retour : ${c.return_fee_percent} % des frais de livraison`,
      links: [{ to: '/admin/zones', label: 'Zones' }, { to: '/admin/tarifs', label: 'Tarifs et retours' }],
      keywords: 'zones communes quartiers tarifs grille prix suppléments express fragile poids frais retour',
    },
    {
      key: 'alerts', icon: '⏰', title: 'Alertes et rappels', to: '/admin/reglages/alertes', permission: 'settings.manage',
      also: [{ to: '/admin/disponibilites', label: 'Livreurs disponibles' }, { to: '/admin/colis-livreurs', label: 'Colis chez les livreurs' }, { to: '/admin/terrain', label: 'Remontées terrain' }, { to: '/admin/statistiques', label: 'Statistiques' }],
      description: 'Quand prévenir le dispatch : courses sans livreur, colis gardés, remontées terrain, point de la semaine.',
      summary: c && `Ramassage ${delay(c.pickup_assign_alert_minutes)} · livraison ${delay(c.delivery_assign_alert_minutes)} · point de la semaine : ${onOff(c.weekly_report)}`,
      keywords: 'alerte délai sans livreur colis gardé livreur relance remontées terrain point semaine rapport',
    },
    {
      key: 'messages', icon: '💬', title: 'Messages aux clients', to: '/admin/whatsapp', permission: 'settings.manage',
      description: 'WhatsApp de l\'entreprise : numéro, envois, modèles, test ; notifications sur téléphone des e-commerçants.',
      keywords: 'whatsapp sms messages modèles envois test notifications push téléphone',
    },
    {
      key: 'merchants', icon: '🏪', title: 'E-commerçants', to: '/admin/reglages/e-commercants', permission: 'settings.manage',
      also: [{ to: '/admin/marchands', label: 'E-commerçants' }],
      description: 'Inscriptions en ligne des e-commerçants.',
      summary: c && (c.merchant_signup ? 'Inscriptions en ligne ouvertes' : 'Inscriptions en ligne fermées'),
      keywords: 'inscription en ligne créer compte e-commerçant marchand',
    },
    {
      key: 'team', icon: '🛵', title: 'Équipe et paie',
      description: 'Rémunération des livreurs, comptes et rôles de l\'équipe.',
      links: [{ to: '/admin/paie-livreurs', label: 'Règles de paie des livreurs', permission: 'settings.manage' }, { to: '/admin/utilisateurs', label: 'Utilisateurs et rôles', permission: 'users.view' }, { to: '/admin/reglages/assistance', label: "Accès de l'assistance Jibiat", permission: 'settings.manage' }],
      keywords: 'paie salaire plan rémunération primes utilisateurs rôles équipe assistance support jibiat accès',
    },
    {
      key: 'integrations', icon: '🔌', title: 'Intégrations',
      description: 'Clés API et webhooks pour les boutiques en ligne.',
      links: [{ to: '/admin/integrations', label: 'API et webhooks', permission: 'integrations.manage', feature: 'api' }],
      keywords: 'api clé webhook boutique',
    },
    {
      key: 'legacy', icon: '📥', title: 'Reprise de l\'ancienne application', to: '/admin/reglages/reprise', permission: 'settings.manage',
      description: 'Brancher l\'ancienne application, reprendre comptes et commandes, contrôler l\'argent, basculer.',
      keywords: 'import reprise ancienne application bascule migration commandes soldes argent livreurs supprimées recomparaison',
    },
    ...(auth.user?.company?.plan ? [{
      key: 'billing', icon: '🧾', title: 'Abonnement', to: '/admin/abonnement', permission: 'settings.manage',
      description: 'Votre formule et vos factures.',
      summary: `Formule ${auth.user.company.plan.name}`,
      keywords: 'abonnement formule facture paiement',
    }] : []),
  ]
})

// Recherche sans accents ni majuscules
const norm = (s) => (s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()

const cards = computed(() => ALL.value
  .filter(allowed)
  .map((card) => ({ ...card, links: card.links?.filter(allowed) }))
  .filter((card) => card.to || card.links?.length)
  .filter((card) => {
    const q = norm(query.value.trim())
    if (!q) return true
    const text = norm([card.title, card.description, card.summary, card.keywords, ...(card.links || []).map((l) => l.label)].join(' '))
    return q.split(/\s+/).every((word) => text.includes(word))
  }))

onMounted(async () => {
  if (!auth.can('settings.manage')) return
  const [c, guide] = await Promise.all([
    http.get(`/companies/${auth.user.company_id}`).catch(() => null),
    http.get('/setup').catch(() => null),
  ])
  company.value = c?.data?.data ?? null
  setup.value = guide?.data?.data ?? null
})
</script>
