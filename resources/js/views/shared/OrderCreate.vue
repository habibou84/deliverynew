<template>
  <div class="max-w-3xl mx-auto">
    <h1 class="text-xl font-bold mb-4">{{ welcome ? 'Course enregistrée' : 'Nouvelle course' }}</h1>

    <!-- Nouvel e-commerçant : ses accès, envoyés ou à envoyer depuis le téléphone de l'équipe -->
    <div v-if="welcome" class="space-y-4">
      <div class="card p-4 space-y-3">
        <p class="text-sm">
          Course <strong>{{ welcome.order.tracking_code }}</strong> créée et compte de <strong>{{ welcome.merchant.business_name }}</strong> ouvert.
        </p>
        <template v-if="welcome.merchant.welcome?.sent">
          <p class="text-sm text-emerald-800">✅ Message de bienvenue envoyé par WhatsApp au {{ welcome.merchant.welcome.phone }}.</p>
          <p class="rounded-xl bg-emerald-50 p-3 text-sm whitespace-pre-line text-emerald-900">{{ welcome.merchant.welcome.text }}</p>
        </template>
        <template v-else-if="welcome.merchant.welcome">
          <p class="text-sm">Envoyez-lui ses accès ({{ welcome.merchant.welcome.phone }}) :</p>
          <div class="flex flex-wrap gap-2">
            <a :href="welcome.merchant.welcome.whatsapp_link" target="_blank" rel="noopener" class="btn-success">Envoyer par WhatsApp</a>
            <button type="button" class="btn-secondary" @click="copy">Copier le lien</button>
          </div>
          <p class="text-xs text-gray-600">« Envoyer par WhatsApp » ouvre WhatsApp avec le message déjà écrit. Le lien est valable 7 jours.</p>
        </template>
      </div>
      <div class="flex flex-wrap gap-2">
        <button type="button" class="btn-secondary" @click="router.push(`${auth.homeRoute}/courses/${welcome.order.id}`)">Voir la course</button>
        <button type="button" class="btn-secondary" @click="welcome = null">Nouvelle course</button>
      </div>
    </div>

    <OrderForm v-else @saved="saved" @saved-again="savedAgain" />
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import OrderForm from '../../components/OrderForm.vue'
import { useAuthStore } from '../../stores/auth'
import { useToastStore } from '../../stores/toasts'

const router = useRouter()
const auth = useAuthStore()
const toasts = useToastStore()
const welcome = ref(null)

function saved(order, meta) {
  toasts.success(`Course ${order.tracking_code} enregistrée.`)
  if (meta?.new_merchant?.welcome) {
    welcome.value = { order, merchant: meta.new_merchant }
    window.scrollTo({ top: 0, behavior: 'smooth' })
    return
  }
  router.push(`${auth.homeRoute}/courses/${order.id}`)
}

// « Enregistrer et nouvelle » : on reste sur le formulaire
function savedAgain(order, meta) {
  const w = meta?.new_merchant?.welcome
  if (w && !w.sent) {
    // Accès à envoyer à la main : on les montre avant la course suivante
    welcome.value = { order, merchant: meta.new_merchant }
    window.scrollTo({ top: 0, behavior: 'smooth' })
    return
  }
  toasts.success(`Course ${order.tracking_code} enregistrée.${w?.sent ? ' Message de bienvenue envoyé.' : ''} Saisissez la suivante.`)
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

async function copy() {
  try {
    await navigator.clipboard.writeText(welcome.value.merchant.welcome.url)
    toasts.success('Lien copié.')
  } catch {
    toasts.error('Copie impossible : sélectionnez le lien à la main.')
  }
}
</script>
