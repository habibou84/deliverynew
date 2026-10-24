<template>
  <div class="min-h-screen bg-white text-slate-900" :style="{ '--app-color': spaceColor('marchand') }">
    <!-- En-tête léger : le nom de l'entreprise et le suivi d'un colis -->
    <header v-if="!nativeApp" class="border-b border-slate-100">
      <div class="max-w-6xl mx-auto px-4 h-16 flex items-center justify-between gap-3">
        <BrandLogo size="md" class="min-w-0" />
        <a href="#suivi" class="shrink-0 text-sm font-semibold text-[var(--app-color)] hover:underline">Suivre un colis</a>
      </div>
    </header>

    <!-- La connexion d'abord ; la présentation à côté (ordinateur) ou en dessous (téléphone) -->
    <section :class="['grid md:grid-cols-2', nativeApp ? 'min-h-screen' : 'md:min-h-[calc(100vh-4rem)]']" :style="{ paddingTop: nativeApp ? 'env(safe-area-inset-top)' : null }">
      <div class="flex flex-col justify-center px-5 py-8 md:px-16 md:py-12">
        <div class="w-full max-w-sm mx-auto md:mx-0 space-y-5">
          <BrandLogo v-if="nativeApp" size="lg" :with-name="false" />
          <div>
            <h1 class="text-[28px] md:text-3xl font-extrabold tracking-tight">Bienvenue 👋</h1>
            <p class="mt-1 text-slate-500">Connectez-vous à votre espace e&#8209;commerçant.</p>
          </div>
          <LoginForm tone="merchant" />
          <div v-if="branding.signup_open" class="text-center text-sm text-slate-600">
            Pas encore client ?
            <RouterLink to="/inscription" class="font-bold text-[var(--app-color)] hover:underline">Créer mon compte gratuitement</RouterLink>
          </div>
          <p v-else class="text-center text-sm text-slate-600">
            Pas encore client ?
            <a v-if="contactLink" :href="contactLink" target="_blank" rel="noopener" class="font-bold text-[var(--app-color)] hover:underline">Ouvrez votre compte</a>
            <span v-else class="font-semibold">Contactez-nous</span>
          </p>
          <InstallBanner app="marchand" class="md:hidden" />
          <NativeCompanySwitch />
        </div>
      </div>

      <div v-if="!nativeApp" class="bg-[#faf8f4] border-t md:border-t-0 md:border-l border-[#efe9df] px-5 py-10 md:px-14 md:py-12 flex flex-col justify-center">
        <div class="max-w-lg space-y-6">
          <h2 class="text-2xl md:text-4xl font-extrabold leading-tight tracking-tight">
            {{ taglineStart }}<span class="text-[var(--app-color)]">{{ taglineEnd }}</span>
          </h2>
          <p class="text-slate-600 md:text-lg">
            Créez vos courses en une minute, suivez chaque livraison en temps réel et recevez l'argent encaissé
            auprès de vos clients sans courir après.
          </p>
          <ul class="grid sm:grid-cols-2 gap-3">
            <li v-for="b in benefits" :key="b.title" class="flex gap-3 rounded-2xl bg-white p-3 ring-1 ring-slate-200/70">
              <span :class="['h-9 w-9 shrink-0 rounded-xl grid place-items-center', b.tone]"><AppIcon :name="b.icon" class="h-[18px] w-[18px]" /></span>
              <span>
                <span class="block text-sm font-bold">{{ b.title }}</span>
                <span class="block text-[13px] text-slate-500">{{ b.text }}</span>
              </span>
            </li>
          </ul>
        </div>
      </div>
    </section>

    <!-- Comment ça marche -->
    <section v-if="!nativeApp" class="max-w-6xl mx-auto px-4 py-12 md:py-16">
      <h2 class="text-2xl md:text-3xl font-bold text-center">Comment ça marche ?</h2>
      <ol class="mt-8 grid md:grid-cols-3 gap-4">
        <li v-for="(s, i) in steps" :key="s.title" class="rounded-2xl bg-white p-6 ring-1 ring-slate-200">
          <span class="grid h-9 w-9 place-items-center rounded-full bg-slate-900 text-sm font-bold text-white">{{ i + 1 }}</span>
          <h3 class="mt-4 font-semibold text-lg">{{ s.title }}</h3>
          <p class="mt-1 text-slate-600">{{ s.text }}</p>
        </li>
      </ol>
    </section>

    <!-- Suivi d'un colis (destinataires) -->
    <section v-if="!nativeApp" id="suivi" class="bg-[#faf8f4] border-y border-[#efe9df]">
      <div class="max-w-3xl mx-auto px-4 py-12 text-center space-y-4">
        <h2 class="text-2xl font-bold">Vous attendez un colis ?</h2>
        <p class="text-slate-600">Entrez le code de suivi reçu par WhatsApp ou SMS (il commence par LV-).</p>
        <form class="flex flex-col sm:flex-row gap-3 max-w-lg mx-auto" @submit.prevent="track">
          <label class="sr-only" for="tracking-code">Code de suivi</label>
          <input id="tracking-code" v-model.trim="trackingCode" class="flex-1 rounded-xl border border-slate-300 bg-white px-4 py-3 text-base uppercase focus:outline-none focus:ring-2 focus:ring-[var(--app-color)]" placeholder="LV-XXXX-XXXX" required>
          <button class="rounded-xl bg-slate-900 px-6 py-3 font-semibold text-white hover:bg-slate-700">Suivre</button>
        </form>
      </div>
    </section>

    <!-- Contact -->
    <footer v-if="!nativeApp" class="bg-slate-900 text-slate-300">
      <div class="max-w-6xl mx-auto px-4 py-10 grid md:grid-cols-3 gap-6 items-start">
        <div class="space-y-2">
          <BrandLogo size="sm" dark />
          <p v-if="branding.address" class="text-sm">{{ branding.address }}</p>
        </div>
        <div class="space-y-2 text-sm">
          <p class="font-semibold text-white">Nous contacter</p>
          <p v-if="branding.phone"><a :href="`tel:${branding.phone}`" class="hover:text-white">{{ branding.phone }}</a></p>
          <p v-if="contactLink"><a :href="contactLink" target="_blank" rel="noopener" class="hover:text-white">WhatsApp</a></p>
          <p v-if="branding.email"><a :href="`mailto:${branding.email}`" class="hover:text-white">{{ branding.email }}</a></p>
        </div>
        <div class="space-y-2 text-sm md:text-right">
          <p>Application mobile : ouvrez ce site sur votre téléphone ; la page vous guide pour l'installer.</p>
          <p class="text-slate-500">© {{ year }} {{ branding.name }}</p>
        </div>
      </div>
    </footer>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import AppIcon from '../../components/mobile/AppIcon.vue'
import BrandLogo from '../../components/auth/BrandLogo.vue'
import LoginForm from '../../components/auth/LoginForm.vue'
import InstallBanner from '../../components/mobile/InstallBanner.vue'
import NativeCompanySwitch from '../../components/auth/NativeCompanySwitch.vue'
import { nativeApp } from '../../native'
import { branding, loadBranding, whatsappLink, spaceColor } from '../../composables/useBranding'

const router = useRouter()
const trackingCode = ref('')
const year = new Date().getFullYear()

// Les atouts, chacun avec sa couleur douce (plus de grand aplat d'une seule couleur)
const benefits = [
  { icon: 'send', tone: 'bg-blue-100 text-blue-700', title: 'Course en 1 minute', text: 'Depuis votre téléphone, WhatsApp ou un fichier Excel.' },
  { icon: 'moto', tone: 'bg-amber-100 text-amber-700', title: 'Suivi en direct', text: 'Chaque étape, chaque livreur, chaque incident.' },
  { icon: 'wallet', tone: 'bg-emerald-100 text-emerald-700', title: 'Encaissements reversés', text: 'Relevés clairs et reversements réguliers.' },
  { icon: 'chat', tone: 'bg-violet-100 text-violet-700', title: 'Clients informés', text: 'Messages WhatsApp à chaque étape importante.' },
]

// Accroche coupée en deux : la fin prend la couleur de l'entreprise
const tagline = computed(() => branding.tagline || 'Vos colis livrés vite, vos clients satisfaits.')
const taglineStart = computed(() => {
  const i = tagline.value.indexOf(', ')
  return i > 0 ? tagline.value.slice(0, i + 2) : ''
})
const taglineEnd = computed(() => tagline.value.slice(taglineStart.value.length))

const steps = [
  { title: 'Créez la course', text: 'Destinataire, commune, montant à encaisser : le tarif s\'affiche aussitôt.' },
  { title: 'Nous ramassons et livrons', text: 'Un livreur passe chez vous, puis livre votre client et encaisse si besoin.' },
  { title: 'Vous êtes payé', text: 'Suivez vos encaissements et recevez vos reversements avec leur relevé.' },
]

const contactLink = computed(() => whatsappLink(branding.phone, `Bonjour, je souhaite ouvrir un compte e-commerçant chez ${branding.name}.`))

function track() {
  router.push(`/suivi/${trackingCode.value.toUpperCase()}`)
}

onMounted(() => loadBranding())
</script>
