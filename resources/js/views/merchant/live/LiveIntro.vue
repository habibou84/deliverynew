<template>
  <LiveVideo v-if="video" @close="video = false" />
  <!-- Replié (à partir de la 3e visite) : un appui le déroule -->
  <details v-if="folded" class="m-card overflow-hidden border-2 border-rose-700">
    <summary class="flex cursor-pointer list-none items-center justify-between p-4 text-[16px] font-bold">
      <span>💡 Comment ça marche ?</span><span class="text-rose-700">Voir ▾</span>
    </summary>
    <div class="space-y-3.5 px-3 pb-4">
      <IntroBody :offer="offer" @video="video = true" />
    </div>
  </details>
  <div v-else class="space-y-3.5">
    <IntroBody :offer="offer" @video="video = true" />
  </div>
</template>

<script setup>
import { defineComponent, h, ref } from 'vue'
import { money } from '../../../utils/format'
import LiveVideo from './LiveVideo.vue'

/**
 * « Comment ça marche » avant « Préparer mon live » : la vidéo (ouverte en grand par-dessus la page), le problème,
 * la solution en 4 étapes, ce qu'on gagne, le prix. En entier aux premières visites, replié ensuite. L'agence de livraison est mise en avant ;
 * Jibiat n'apparaît que dans « Propulsé par Jibiat » sous le bouton.
 */
defineProps({
  offer: { type: Object, required: true },
  folded: Boolean,
})

// Vidéo « Comment ça marche » (tools/motion, scène live-comment-ca-marche), voix off et sous-titres
const VIDEO = '/videos/live/live-comment-ca-marche-v'
const video = ref(false)

// Contenu de la page (partagé par la version complète et la version repliée)
const IntroBody = defineComponent({
  props: { offer: { type: Object, required: true } },
  emits: ['video'],
  setup(props, { emit }) {
    const card = (cls, children) => h('section', { class: `rounded-[22px] bg-white p-4 shadow-soft ${cls}` }, children)
    const tag = (cls, text) => h('span', { class: `mb-2 inline-block rounded-full px-2.5 py-1 text-xs font-extrabold ${cls}` }, text)
    const row = (bg, icon, title, text) => h('div', { class: 'my-2.5 flex items-start gap-2.5' }, [
      h('span', { class: `grid h-11 w-11 shrink-0 place-items-center rounded-2xl text-[22px] ${bg}`, 'aria-hidden': 'true' }, icon),
      h('div', [h('b', { class: 'block text-[16px]' }, title), h('p', { class: 'mt-0.5 text-[15px] text-slate-600' }, text)]),
    ])
    const step = (n, illustration, title, text) => h('div', { class: 'my-3.5 flex items-center gap-3' }, [
      h('span', { class: 'grid h-9 w-9 shrink-0 place-items-center rounded-full bg-slate-900 text-[16px] font-extrabold text-white' }, n),
      h('div', { class: 'h-[84px] w-[104px] shrink-0 overflow-hidden rounded-2xl' }, illustration),
      h('div', { class: 'min-w-0' }, [h('b', { class: 'block text-[16px] leading-tight' }, title), h('span', { class: 'text-[14px] text-slate-600' }, text)]),
    ])
    const bold = (text) => h('b', { class: 'text-slate-900' }, text)
    const zero = (value, text, blue) => h('div', { class: ['my-2.5 flex items-center gap-3.5 rounded-[18px] border-2 px-4 py-3', blue ? 'border-blue-500 bg-blue-50' : 'border-emerald-500 bg-emerald-50'] }, [
      h('b', { class: ['min-w-[44px] text-center font-black leading-none', blue ? 'text-[30px]' : 'text-[40px] text-emerald-700'] }, value),
      h('span', { class: ['text-[19px] font-extrabold', blue ? 'text-blue-900' : 'text-emerald-900'] }, text),
    ])

    return () => {
      const o = props.offer
      const agency = o.agency || 'Votre agence'
      const comments = [['Awa', 'je prends le sac noir'], ['Mariam', 'moi aussi !! taille M'], ['Fatou', 'c\'est combien ?'], ['Kadi', 'je veux la robe 😍'], ['Aya', 'dispo encore ?']]
      return [
        // 1. Titre
        h('div', { class: 'rounded-[24px] bg-gradient-to-br from-rose-700 to-violet-600 p-5 text-white' }, [
          h('p', { class: 'text-xs font-extrabold uppercase tracking-wider text-rose-100' }, `🛵 ${agency} · Comment ça marche`),
          h('h1', { class: 'my-1.5 text-[26px] font-extrabold leading-tight' }, 'Vendez plus en live, sans perdre une seule commande'),
          h('p', { class: 'text-[16px] text-rose-50' }, ['Vous montrez vos produits. Les clients commandent par WhatsApp et ', h('b', agency), ' reçoit les commandes.']),
          h('p', { class: 'mt-3 rounded-2xl bg-white px-3.5 py-2.5 text-[18px] font-extrabold leading-normal text-rose-700 underline decoration-[3px] underline-offset-4' }, ['✅ Vous n\'avez rien à répondre.', h('br'), '✅ Vous n\'avez rien à enregistrer.']),
        ]),
        // Vidéo : un appui l'ouvre en grand, par-dessus la page
        h('button', { type: 'button', class: 'relative block w-full overflow-hidden rounded-[22px] bg-black text-left shadow-soft', 'aria-label': 'Voir la vidéo : comment ça marche', onClick: () => emit('video') }, [
          h('img', { src: `${VIDEO}.jpg`, alt: '', class: 'h-44 w-full object-cover opacity-70' }),
          h('span', { class: 'absolute inset-0 flex flex-col items-center justify-center gap-2 text-white' }, [
            h('span', { class: 'grid h-16 w-16 place-items-center rounded-full bg-white text-[26px] text-rose-700 shadow-lg', 'aria-hidden': 'true' }, '▶'),
            h('b', { class: 'text-[18px]' }, 'Voir la vidéo (1 min 20)'),
          ]),
        ]),
        // 2. Le problème
        card('', [
          tag('bg-red-50 text-red-800', '😩 LE PROBLÈME'),
          h('h2', { class: 'mb-2.5 text-[19px] font-extrabold' }, 'Pendant un live, tout va trop vite'),
          h('div', { class: 'space-y-1.5 rounded-2xl bg-slate-900 p-2.5 text-[13px] text-white', 'aria-hidden': 'true' },
            comments.map(([n, t]) => h('div', { class: 'rounded-xl bg-slate-800 px-2.5 py-1.5' }, [h('b', n), ' ', h('span', { class: 'text-slate-400' }, t)]))),
          row('bg-red-50', '💬', 'Répondre à chaque client', 'Pendant que vous parlez, les messages s\'empilent.'),
          row('bg-red-50', '📝', 'Vous notez à la main', 'Les messages défilent, vous en ratez.'),
          row('bg-red-50', '❓', '« Laquelle ? Quelle taille ? »', 'Les clients se trompent d\'article, vous perdez du temps.'),
          row('bg-red-50', '📦', 'Vendu deux fois', 'Un produit épuisé est promis à 3 clients.'),
          row('bg-red-50', '🌙', 'Le soir, tout recopier', 'Enregistrer chaque commande et chaque livraison, une par une.'),
        ]),
        // 3. La solution
        card('', [
          tag('bg-indigo-50 text-indigo-800', '✅ LA SOLUTION EN 4 ÉTAPES'),
          h('h2', { class: 'text-[19px] font-extrabold' }, `${agency} fait le travail pour vous`),
          step('1', h('div', { class: 'h-full rounded-2xl border border-slate-200 bg-white p-2 text-xs leading-[1.7]' }, ['☑️ Robe wax', h('br'), '☑️ Sac cuir', h('br'), '☐ Parfum']),
            'Choisissez les produits du live', 'Dans votre catalogue, ou en photo : l\'IA écrit le nom.'),
          step('2', h('div', { class: 'grid h-full grid-cols-3 gap-1 bg-slate-900 p-1.5' }, ['A1', 'A2', 'A3', 'A4', 'A5', 'A6'].map((c) => h('div', { class: 'relative rounded bg-white' }, [h('em', { class: 'absolute left-0.5 top-0.5 rounded-sm bg-slate-900 px-0.5 text-xs font-extrabold not-italic text-white' }, c)]))),
            'La photo à épingler est prête', 'Préparée automatiquement : produits, codes A1, A2…, prix, votre numéro.'),
          step('3', h('div', { class: 'h-full bg-[#e7f8ee] p-2 text-xs' }, [h('div', { class: 'w-3/4 rounded-lg bg-white px-2 py-1' }, 'A2'), h('div', { class: 'ml-auto mt-1.5 whitespace-nowrap rounded-lg bg-[#25d366] px-2 py-1 text-xs text-white' }, '✅ Commande notée !')]),
            'Les clients commandent par WhatsApp', ['Ils envoient le code. La réponse part toute seule : ', bold('vous n\'avez rien à répondre.')]),
          step('4', h('div', { class: 'grid h-full place-items-center bg-emerald-50 text-[40px]' }, '🛵'),
            `${agency} reçoit les commandes`, ['Directement, avec le nom, le numéro et le quartier. ', bold('Vous n\'enregistrez rien'), ' : tout se fait seul.']),
        ]),
        // 4. Ce que vous gagnez
        card('', [
          tag('bg-emerald-50 text-emerald-800', '💪 CE QUE VOUS GAGNEZ'),
          h('h2', { class: 'mb-2.5 text-[19px] font-extrabold' }, `Vous vendez, ${agency} s'occupe du reste`),
          zero('0', 'message à répondre'), zero('0', 'commande à enregistrer'), zero('0', 'commande oubliée'), zero('📊', 'le bilan de votre live, tout prêt', true),
          row('bg-emerald-50', '🎤', 'Vous restez devant la caméra', 'Pas besoin de lire les messages ni de noter : vous présentez vos produits.'),
          row('bg-emerald-50', '🔔', 'Stock à jour en direct', '« Il en reste 2 », « Épuisé » : vous le dites au bon moment.'),
          row('bg-emerald-50', '⏳', 'Liste d\'attente', 'Produit épuisé ? Le client est noté et prévenu.'),
          row('bg-emerald-50', '⚠️', 'Clients à risque signalés', 'Ceux qui refusent souvent : demandez un acompte.'),
        ]),
        // 6. Prix
        card('', [
          h('h2', { class: 'mb-1 text-[19px] font-extrabold' }, '💰 Combien ça coûte'),
          o.offered_by_company
            ? h('p', { class: 'py-2 text-[16px] font-bold text-emerald-700' }, 'Votre agence vous offre vos lives.')
            : [
                h('p', { class: 'py-1 text-[14.5px] text-slate-600' }, ['Vous payez ', bold('chaque commande reçue en live'), ', par packs. Elles sont retirées de votre solde à la fin du live :']),
                o.free_left ? h('div', { class: 'flex justify-between border-b border-slate-100 py-2.5 text-[15px]' }, [h('span', `Vos ${o.free_left} premières commandes en live`), h('b', { class: 'text-emerald-700' }, 'Offertes')]) : null,
                ...(o.packs || []).map((p) => h('div', { class: 'flex items-center justify-between gap-2 border-b border-slate-100 py-2.5 text-[15px]' }, [
                  h('span', [`${p.orders} commandes`, p.advised ? h('span', { class: 'ml-1.5 rounded-full bg-amber-400 px-1.5 py-0.5 text-[11px] font-black' }, 'Conseillé') : null,
                    h('span', { class: 'block text-[13px] text-slate-600' }, `${money(p.unit)} la commande${p.cards ? ` · ${p.cards} fiches IA incluses` : ''}`)]),
                  h('b', money(p.price)),
                ])),
                o.prices.month ? h('div', { class: 'flex justify-between border-b border-slate-100 py-2.5 text-[15px]' }, [h('span', 'Ou l\'illimité 30 jours, sans compter les commandes'), h('b', money(o.prices.month))]) : null,
                o.prices.welcome_cards ? h('div', { class: 'flex justify-between border-b border-slate-100 py-2.5 text-[15px]' }, [h('span', `${o.prices.welcome_cards} fiches IA (photo + IA) pour commencer`), h('b', { class: 'text-emerald-700' }, 'Offertes')]) : null,
                h('div', { class: 'flex justify-between py-2.5 text-[15px]' }, [h('span', 'Ajouter un produit à la main'), h('b', { class: 'text-emerald-700' }, 'Toujours gratuit')]),
                h('p', { class: 'text-center text-[13px] text-slate-600' }, o.pay_online
                  ? 'Packs payés par Orange Money, MTN, Moov, Wave ou carte. Il faut au moins '+o.min_orders+' commandes dans votre solde pour démarrer un live.'
                  : 'Retenu sur votre prochain reversement : rien à payer avant.'),
              ],
        ]),
      ]
    }
  },
})
</script>
