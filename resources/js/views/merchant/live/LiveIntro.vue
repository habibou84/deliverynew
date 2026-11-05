<template>
  <!-- Replié (à partir de la 3e visite) : un appui le déroule -->
  <details v-if="folded" class="m-card overflow-hidden border-2 border-rose-700">
    <summary class="flex cursor-pointer list-none items-center justify-between p-4 text-[16px] font-bold">
      <span>💡 Comment ça marche ?</span><span class="text-rose-700">Voir ▾</span>
    </summary>
    <div class="space-y-3.5 px-3 pb-4">
      <IntroBody :offer="offer" />
    </div>
  </details>
  <div v-else class="space-y-3.5">
    <IntroBody :offer="offer" />
  </div>
</template>

<script setup>
import { defineComponent, h, ref } from 'vue'
import { money } from '../../../utils/format'

/**
 * « Comment ça marche » avant « Préparer mon live » : le problème, la solution en 4 étapes, ce qu'on gagne, la
 * vidéo, le prix. En entier aux premières visites, replié ensuite.
 */
defineProps({
  offer: { type: Object, required: true },
  folded: Boolean,
})

const VIDEOS = [
  { file: 'formation-01', title: 'Pourquoi le live marche' },
  { file: 'formation-05', title: 'Montrer le code' },
  { file: 'formation-07', title: 'Ce qu\'il faut dire' },
  { file: 'formation-08', title: 'Le déroulé idéal' },
]

// Contenu de la page (partagé par la version complète et la version repliée)
const IntroBody = defineComponent({
  props: { offer: { type: Object, required: true } },
  setup(props) {
    const video = ref(VIDEOS[0])
    const card = (cls, children) => h('section', { class: `rounded-[22px] bg-white p-4 shadow-soft ${cls}` }, children)
    const tag = (cls, text) => h('span', { class: `mb-2 inline-block rounded-full px-2.5 py-1 text-[12px] font-extrabold ${cls}` }, text)
    const row = (bg, icon, title, text) => h('div', { class: 'my-2.5 flex items-start gap-2.5' }, [
      h('span', { class: `grid h-11 w-11 shrink-0 place-items-center rounded-2xl text-[22px] ${bg}`, 'aria-hidden': 'true' }, icon),
      h('div', [h('b', { class: 'block text-[16px]' }, title), h('p', { class: 'mt-0.5 text-[15px] text-slate-600' }, text)]),
    ])
    const step = (n, illustration, title, text) => h('div', { class: 'my-3.5 flex items-center gap-3' }, [
      h('span', { class: 'grid h-9 w-9 shrink-0 place-items-center rounded-full bg-slate-900 text-[16px] font-extrabold text-white' }, n),
      h('div', { class: 'h-[84px] w-[104px] shrink-0 overflow-hidden rounded-2xl' }, illustration),
      h('div', { class: 'min-w-0' }, [h('b', { class: 'block text-[16px] leading-tight' }, title), h('span', { class: 'text-[14px] text-slate-600' }, text)]),
    ])
    const kpi = (value, label) => h('div', { class: 'rounded-2xl bg-slate-50 p-3' }, [h('b', { class: 'block text-[24px]' }, value), h('span', { class: 'text-[13px] text-slate-600' }, label)])
    const bold = (text) => h('b', { class: 'text-slate-900' }, text)

    return () => {
      const o = props.offer
      const comments = [['Awa', 'je prends le sac noir'], ['Mariam', 'moi aussi !! taille M'], ['Fatou', 'c\'est combien ?'], ['Kadi', 'je veux la robe 😍'], ['Aya', 'dispo encore ?']]
      return [
        // 1. Titre
        h('div', { class: 'rounded-[24px] bg-gradient-to-br from-rose-700 to-violet-600 p-5 text-white' }, [
          h('p', { class: 'text-[12px] font-extrabold tracking-wider text-rose-100' }, 'COMMENT ÇA MARCHE · 2 MIN'),
          h('h1', { class: 'my-1.5 text-[26px] font-extrabold leading-tight' }, 'Vendez plus en live, sans perdre une seule commande'),
          h('p', { class: 'text-[16px] text-rose-50' }, 'Vous montrez vos produits. Les clientes commandent par WhatsApp, votre agence reçoit les commandes. Vous n\'avez rien à répondre ni à enregistrer.'),
        ]),
        // 2. Le problème
        card('', [
          tag('bg-red-50 text-red-800', '😩 LE PROBLÈME'),
          h('h2', { class: 'mb-2.5 text-[19px] font-extrabold' }, 'Pendant un live, tout va trop vite'),
          h('div', { class: 'space-y-1.5 rounded-2xl bg-slate-900 p-2.5 text-[13px] text-white', 'aria-hidden': 'true' },
            comments.map(([n, t]) => h('div', { class: 'rounded-xl bg-slate-800 px-2.5 py-1.5' }, [h('b', n), ' ', h('span', { class: 'text-slate-400' }, t)]))),
          row('bg-red-50', '💬', 'Répondre à chaque cliente', 'Pendant que vous parlez, les messages s\'empilent.'),
          row('bg-red-50', '📝', 'Vous notez à la main', 'Les messages défilent, vous en ratez.'),
          row('bg-red-50', '❓', '« Laquelle ? Quelle taille ? »', 'Les clientes se trompent d\'article, vous perdez du temps.'),
          row('bg-red-50', '📦', 'Vendu deux fois', 'Un produit épuisé est promis à 3 clientes.'),
          row('bg-red-50', '🌙', 'Le soir, tout recopier', 'Enregistrer chaque commande et chaque livraison, une par une.'),
        ]),
        // 3. La solution
        card('', [
          tag('bg-indigo-50 text-indigo-800', '✅ LA SOLUTION EN 4 ÉTAPES'),
          h('h2', { class: 'text-[19px] font-extrabold' }, 'Jibiat fait le travail pour vous'),
          step('1', h('div', { class: 'h-full rounded-2xl border border-slate-200 bg-white p-2 text-[12px] leading-[1.7]' }, ['☑️ Robe wax', h('br'), '☑️ Sac cuir', h('br'), '☐ Parfum']),
            'Choisissez les produits du live', 'Dans votre catalogue, ou en photo : l\'IA écrit le nom.'),
          step('2', h('div', { class: 'grid h-full grid-cols-3 gap-1 bg-slate-900 p-1.5' }, ['A1', 'A2', 'A3', 'A4', 'A5', 'A6'].map((c) => h('div', { class: 'relative rounded bg-white' }, [h('em', { class: 'absolute left-0.5 top-0.5 rounded-sm bg-slate-900 px-0.5 text-[9px] font-extrabold not-italic text-white' }, c)]))),
            'La photo à épingler est prête', 'Préparée automatiquement : produits, codes A1, A2…, prix, votre numéro.'),
          step('3', h('div', { class: 'h-full bg-[#e7f8ee] p-2 text-[12px]' }, [h('div', { class: 'w-3/4 rounded-lg bg-white px-2 py-1' }, 'A2'), h('div', { class: 'ml-auto mt-1.5 whitespace-nowrap rounded-lg bg-[#25d366] px-2 py-1 text-[10px] text-white' }, '✅ Commande notée !')]),
            'Les clientes commandent par WhatsApp', ['Elles envoient le code. La réponse part toute seule : ', bold('vous n\'avez rien à répondre.')]),
          step('4', h('div', { class: 'grid h-full place-items-center bg-emerald-50 text-[40px]' }, '🛵'),
            'Votre agence reçoit les commandes', ['Directement, avec le nom, le numéro et le quartier. ', bold('Vous n\'enregistrez rien'), ' : tout se fait seul.']),
        ]),
        // 4. Ce que vous gagnez
        card('', [
          tag('bg-emerald-50 text-emerald-800', '💪 CE QUE VOUS GAGNEZ'),
          h('h2', { class: 'mb-2.5 text-[19px] font-extrabold' }, 'Vous vendez, Jibiat s\'occupe du reste'),
          h('div', { class: 'grid grid-cols-2 gap-2.5' }, [kpi('0', 'message à répondre'), kpi('0', 'commande à enregistrer'), kpi('0', 'commande oubliée'), kpi('📊', 'le bilan de votre live')]),
          row('bg-emerald-50', '🎤', 'Vous restez devant la caméra', 'Pas besoin de lire les messages ni de noter : vous présentez vos produits.'),
          row('bg-emerald-50', '🔔', 'Stock à jour en direct', '« Il en reste 2 », « Épuisé » : vous le dites au bon moment.'),
          row('bg-emerald-50', '⏳', 'Liste d\'attente', 'Produit épuisé ? La cliente est notée et prévenue.'),
          row('bg-emerald-50', '⚠️', 'Clientes à risque signalées', 'Celles qui refusent souvent : demandez un acompte.'),
        ]),
        // 5. Vidéo
        card('', [
          h('h2', { class: 'mb-2.5 text-[19px] font-extrabold' }, '🎬 Voir en vidéo'),
          h('video', { key: video.value.file, class: 'mx-auto max-h-[420px] w-full rounded-2xl bg-black', controls: true, playsinline: true, preload: 'none', poster: `/videos/live/${video.value.file}.jpg`, src: `/videos/live/${video.value.file}.mp4` }),
          h('div', { class: 'mt-2.5 flex flex-wrap gap-1.5' }, VIDEOS.map((v) => h('button', {
            type: 'button',
            class: ['rounded-full px-3 py-1.5 text-[13px] font-semibold', v.file === video.value.file ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-800'],
            onClick: () => { video.value = v },
          }, `▶ ${v.title}`))),
        ]),
        // 6. Prix
        card('', [
          h('h2', { class: 'mb-1 text-[19px] font-extrabold' }, '💰 Combien ça coûte'),
          o.offered_by_company
            ? h('p', { class: 'py-2 text-[16px] font-bold text-emerald-700' }, 'Votre agence vous offre vos lives.')
            : [
                o.trial_left ? h('div', { class: 'flex justify-between border-b border-slate-100 py-2.5 text-[15px]' }, [h('span', 'Votre 1er live'), h('b', { class: 'text-emerald-700' }, 'Offert')]) : null,
                h('div', { class: 'flex justify-between border-b border-slate-100 py-2.5 text-[15px]' }, [h('span', `Un live (${o.prices.cards_per_live} fiches IA comprises)`), h('b', money(o.prices.live))]),
                h('div', { class: 'flex justify-between py-2.5 text-[15px]' }, [h('span', 'Forfait du mois, lives sans limite'), h('b', money(o.prices.month))]),
                h('p', { class: 'text-center text-[13px] text-slate-500' }, 'Retenu sur votre prochain reversement : rien à payer avant.'),
              ],
        ]),
      ]
    }
  },
})
</script>
