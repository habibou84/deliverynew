<template>
  <SettingsPage :key="route.path" :icon="step.icon" :title="step.title" :intro="step.intro" back="/admin/reglages/reprise" back-label="Reprise de l'ancienne application">
    <template v-if="name === 'connect'">
      <p class="rounded-lg bg-blue-50 p-3 text-sm text-blue-900">
        Trois fichiers à copier dans l'ancienne application, chacun avec sa clé. Les explications sont en tête de chaque fichier.
      </p>
      <LegacyOrdersSettings part="connect" />
      <LegacyImportSettings part="connect" />
      <LegacySwitchSettings part="connect" />
    </template>

    <LegacyImportSettings v-else-if="name === 'accounts'" />

    <LegacyOrdersSettings v-else-if="name === 'orders'" part="import" />

    <template v-else-if="name === 'checks'">
      <section class="card p-4 flex flex-wrap items-center gap-3">
        <div class="flex-1 min-w-[16rem]">
          <h2 class="font-semibold">Zones et tarifs</h2>
          <p class="text-sm text-gray-600">Rattacher les zones reprises aux lieux de la liste commune, reclasser les courses « À préciser », puis valider les prix de l'ancienne application.</p>
        </div>
        <RouterLink to="/admin/reglages/reprise/zones" class="btn-secondary">Rattacher les zones ›</RouterLink>
        <RouterLink to="/admin/reglages/reprise/tarifs" class="btn-secondary">Tarifs proposés ›</RouterLink>
      </section>
      <LegacyRecheckSettings />
      <LegacyOrdersSettings part="balances" />
      <section class="card p-4 flex flex-wrap items-center gap-3">
        <div class="flex-1 min-w-[16rem]">
          <h2 class="font-semibold">Argent chez les livreurs : point de départ</h2>
          <p class="text-sm text-gray-600">L'ancienne application n'enregistrait pas toujours l'argent remis par les livreurs. Faites le point avec chacun : avant la date choisie, l'argent est compté comme remis.</p>
        </div>
        <RouterLink to="/admin/reglages/reprise/argent-livreurs" class="btn-secondary">Faire le point ›</RouterLink>
      </section>
      <LegacyPurgeSettings />
    </template>

    <LegacySwitchSettings v-else-if="name === 'switch'" />
  </SettingsPage>
</template>

<script setup>
import { computed } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import SettingsPage from '../../../components/settings/SettingsPage.vue'
import LegacyImportSettings from '../../../components/LegacyImportSettings.vue'
import LegacyOrdersSettings from '../../../components/LegacyOrdersSettings.vue'
import LegacyPurgeSettings from '../../../components/LegacyPurgeSettings.vue'
import LegacyRecheckSettings from '../../../components/LegacyRecheckSettings.vue'
import LegacySwitchSettings from '../../../components/LegacySwitchSettings.vue'

/**
 * Réglages › Reprise › une étape (brancher, comptes, commandes, contrôles, bascule), choisie par la route.
 */
const STEPS = {
  connect: { icon: '🔌', title: 'Brancher l\'ancienne application', intro: 'Les modules à copier dans l\'ancienne application et leurs clés, au même endroit.' },
  accounts: { icon: '👥', title: 'Comptes et zones', intro: 'E-commerçants, livreurs et zones envoyés par l\'ancienne application.' },
  orders: { icon: '📦', title: 'Commandes', intro: 'Reprise des commandes, puis copie chaque minute jusqu\'à la bascule.' },
  checks: { icon: '🔎', title: 'Contrôles et ménage', intro: 'Recomparer avec l\'ancienne application, vérifier l\'argent, clôturer les vieilles courses.' },
  switch: { icon: '🚀', title: 'Bascule vers Jibiat', intro: 'Le jour où tout le monde passe sur Jibiat, déjà connecté.' },
}

const route = useRoute()
const name = computed(() => route.meta.step)
const step = computed(() => STEPS[name.value])
</script>
