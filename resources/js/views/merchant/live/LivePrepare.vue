<template>
  <div class="space-y-3 pb-10">
    <p v-if="error" class="rounded-2xl bg-red-50 px-4 py-3 text-[15px] text-red-700" role="alert">{{ error }}</p>
    <p v-if="!d && !error" class="m-card p-6 text-center text-slate-500">Chargement…</p>

    <!-- Comment ça marche : en entier aux premières visites, ou à la demande -->
    <LiveIntro v-if="d && introOpen && !running" :offer="d.offer" />

    <!-- ══ Une seule page : les étapes, cochées une à une (chacune s'ouvre en grand) ══ -->
    <!-- Live en cours : seulement le bandeau, pour y retourner -->
    <section v-if="d && running" class="rounded-[24px] bg-gradient-to-br from-rose-800 via-rose-600 to-rose-400 p-5 text-white">
      <span class="inline-flex items-center gap-2 rounded-full bg-white/20 px-3 py-1 text-[12.5px] font-extrabold"><i class="block h-2 w-2 animate-pulse rounded-full bg-white" />EN DIRECT</span>
      <h2 class="mt-2 text-[20px] font-extrabold leading-tight">{{ d.live.name }}</h2>
      <p class="mt-1 text-[30px] font-black leading-tight tabular-nums">{{ money(d.totals?.amount || 0) }}</p>
      <p class="text-[14px] font-bold opacity-90">{{ d.totals?.orders || 0 }} commande{{ (d.totals?.orders || 0) > 1 ? 's' : '' }}<template v-if="d.totals?.waiting"> · {{ d.totals.waiting }} en attente</template></p>
      <RouterLink :to="`/marchand/live/${d.live.id}`" class="mt-4 block rounded-2xl bg-white py-3 text-center text-[16px] font-extrabold text-rose-700">Suivre le live en cours</RouterLink>
    </section>

    <template v-else-if="d && !introOpen">
      <section class="rounded-[22px] bg-gradient-to-br from-[#1e3478] to-[#3c5bc4] px-4 py-5 text-center text-white">
        <h2 class="text-[25px] font-black leading-tight">Votre live en {{ stepList.length }} étapes</h2>
        <p class="mt-1 text-[15.5px] font-bold text-blue-50">Les clients envoient le code, la commande part à votre agence.</p>
        <button type="button" class="mt-3 rounded-full bg-white/20 px-4 py-1.5 text-[14px] font-bold" @click="introOpen = true">🎬 Comment ça marche</button>
      </section>

      <div v-for="(s, i) in stepList" :key="s.key" :class="['flex items-center gap-3 rounded-[20px] bg-white p-3.5 shadow-soft', s.state === 'current' ? 'ring-2 ring-[var(--app-color)]' : '', s.state === 'todo' ? 'opacity-55' : '']">
        <span :class="['grid h-10 w-10 shrink-0 place-items-center rounded-full text-[17px] font-black', s.state === 'done' ? 'bg-emerald-600 text-white' : s.state === 'current' ? 'bg-[var(--app-color)] text-white' : 'bg-slate-200 text-slate-500']">{{ s.state === 'done' ? '✓' : i + 1 }}</span>
        <div class="min-w-0 flex-1">
          <b class="block text-[17px] leading-tight">{{ s.title }}</b>
          <span :class="['block text-[13.5px]', s.alert ? 'font-semibold text-red-700' : 'text-slate-500']">{{ s.text }}</span>
        </div>
        <button v-if="s.panel && s.state !== 'todo'" type="button" :class="['shrink-0 py-2 pl-2 text-[15px] font-black', s.state === 'done' ? 'text-emerald-700' : 'text-[var(--app-color)]']" @click="open(s.panel)">{{ s.state === 'done' ? s.again : 'Ouvrir ▸' }}</button>
      </div>

      <!-- Étape « Commencer » : le grand bouton -->
      <template v-if="current === 'start'">
        <button type="button" class="block w-full rounded-2xl bg-rose-600 py-4 text-[18px] font-black text-white shadow-soft active:scale-[.99] disabled:opacity-60" :disabled="saving" @click="start">{{ d.offer.can_start ? '● Je commence mon live' : '● Recharger et commencer' }}</button>
        <p class="text-center text-[14px] text-slate-500">{{ priceLine }}</p>
        <!-- Payé au palier : prix selon les commandes, crédit, forfait du mois -->
        <div v-if="d.offer.next_plan === 'tier'" class="space-y-2 rounded-2xl bg-white p-3.5 shadow-soft">
          <b class="block text-[15.5px]">Prix d'un live, selon vos commandes</b>
          <LiveTiers :tiers="d.offer.tiers" />
          <div v-if="d.offer.pay_online" class="flex items-center gap-2 pt-1">
            <span class="flex-1 text-[14.5px]">Crédit live : <b :class="d.offer.credit < 0 ? 'text-red-700' : ''">{{ money(d.offer.credit) }}</b></span>
            <button type="button" class="rounded-xl bg-slate-900 px-3.5 py-2 text-[14.5px] font-bold text-white" @click="paySheet = true">Recharger</button>
          </div>
          <button v-if="offerMonthly" type="button" class="block w-full rounded-xl bg-slate-50 px-3 py-2.5 text-left" :disabled="saving" @click="subscribeMonthly">
            <b class="block text-[15px]">📅 Ou le forfait du mois : {{ money(d.offer.prices.month) }}</b>
            <span class="text-[13px] text-slate-500">Lives sans limite pendant 30 jours, sans compter les commandes ›</span>
          </button>
        </div>
        <LivePaySheet v-if="paySheet" :offer="d.offer" @close="paySheet = false" @offer="d.offer = $event" />
      </template>
      <RouterLink to="/marchand/live/passes" class="block pt-1 text-center text-[14px] font-bold text-[var(--app-color)]">Voir mes lives passés ▸</RouterLink>
    </template>

    <!-- Bouton du bas de « Comment ça marche » -->
    <div v-if="d && introOpen && !running" :class="embedded ? 'pt-1' : 'fixed inset-x-0 bottom-0 z-20 border-t border-slate-200 bg-white px-4 py-3 pb-[max(.75rem,env(safe-area-inset-bottom))]'">
      <button type="button" class="m-btn-primary w-full" @click="closeIntro">Préparer mon live</button>
      <p class="mt-1.5 text-center text-[12px] text-slate-400">Propulsé par <b>Jibiat</b></p>
    </div>

    <!-- ══ Une étape ouverte, en grand par-dessus la page ══ -->
    <div v-if="d && panel" class="fixed inset-0 z-50 flex flex-col bg-[#F4F6F8]" role="dialog" aria-modal="true" :aria-label="panelTitle">
      <header class="flex shrink-0 items-center gap-2 border-b border-slate-200 bg-white px-4 pb-3 pt-[max(.75rem,env(safe-area-inset-top))]">
        <button v-if="panel === 'nouveau'" type="button" class="grid h-9 w-9 place-items-center rounded-full bg-slate-100 text-xl font-bold text-slate-700" aria-label="Revenir aux produits" @click="panel = 'produits'">‹</button>
        <h2 class="min-w-0 flex-1 truncate text-[18px] font-black">{{ panelTitle }}</h2>
        <button type="button" class="grid h-9 w-9 place-items-center rounded-full bg-slate-100 text-[15px] text-slate-600" aria-label="Fermer" @click="closePanel">✕</button>
      </header>

      <div class="min-h-0 flex-1 space-y-3.5 overflow-y-auto overscroll-contain px-4 py-4">
        <!-- ── WhatsApp ── -->
        <template v-if="panel === 'whatsapp'">
          <!-- Court, comme Jibiat Marchand : une phrase, 3 étapes, un bouton -->
          <div v-if="!d.whatsapp?.auto" class="rounded-[22px] bg-[#E8F8EE] px-4 py-3.5">
            <p class="text-[21px] font-black leading-tight text-[#0B6B3A]">💬 Vos clients commandent sur WhatsApp</p>
            <p class="mt-1 text-[15.5px] text-slate-700">Ils envoient le code du produit : la réponse part toute seule.</p>
          </div>

          <!-- Connexion pas encore ouverte sur cette installation : le lien du live en attendant -->
          <p v-if="!waConnectable" class="rounded-2xl bg-amber-50 px-4 py-3 text-[15px] text-amber-900">La connexion de WhatsApp n'est pas encore ouverte par votre agence. En attendant, vos clients commandent par <b>le lien du live</b>.</p>

          <LiveWhatsAppConnect :initial="d.whatsapp" :default-phone="d.offer.phone" @connected="onWhatsApp" @next="open('produits')" />

          <div v-if="!d.whatsapp?.auto" class="space-y-1 pt-1 text-center">
            <button type="button" class="text-[15px] font-bold text-[var(--app-color)]" @click="skipWhatsApp">{{ waConnectable ? 'Pas de numéro spécial ? Continuer sans WhatsApp ›' : 'Continuer sans WhatsApp ›' }}</button>
            <p class="text-[13px] text-slate-400">Sans WhatsApp, vos clients commandent par le lien du live.</p>
          </div>
        </template>

        <!-- ── Produits ── -->
        <template v-if="panel === 'produits'">
          <!-- Reprendre un live passé -->
          <div v-if="d.past?.length" class="m-card overflow-hidden">
            <button type="button" class="tap flex w-full items-center justify-between gap-2 px-4 py-3 text-left" :aria-expanded="pastOpen" @click="pastOpen = !pastOpen">
              <span><b class="block text-[16px]">📋 Reprendre un live passé</b><span class="text-[13.5px] text-slate-500">Ses produits, leur ordre et leurs prix</span></span>
              <span class="text-[18px] text-slate-400" aria-hidden="true">{{ pastOpen ? '▴' : '▾' }}</span>
            </button>
            <div v-if="pastOpen" class="divide-y divide-slate-100 border-t border-slate-100">
              <div v-for="pl in d.past" :key="pl.id" class="flex items-center gap-3 px-4 py-2.5">
                <span class="min-w-0 flex-1">
                  <b class="block truncate text-[15px]">{{ pl.name }}</b>
                  <span class="block truncate text-[13px] text-slate-500">{{ pastDay(pl.ended_at) }} · {{ pl.items.length }} produit{{ pl.items.length > 1 ? 's' : '' }} · {{ pl.orders_count }} cde{{ pl.orders_count > 1 ? 's' : '' }} · {{ pl.items.map((i) => i.name).join(', ') }}</span>
                </span>
                <button type="button" class="shrink-0 rounded-xl bg-slate-900 px-3 py-2 text-[14px] font-bold text-white disabled:opacity-50" :disabled="saving" @click="reuse(pl)">Reprendre</button>
              </div>
            </div>
          </div>
        <p v-if="d.offer.offered_by_company" class="rounded-2xl bg-emerald-50 px-4 py-3 text-[15px] text-emerald-800">Votre agence vous offre vos lives.</p>
        <p v-else-if="d.offer.next_plan === 'tier' && d.offer.free_left" class="rounded-2xl bg-emerald-50 px-4 py-3 text-[15px] text-emerald-800">Il vous reste <b>{{ d.offer.free_left }} commandes offertes</b> en live.</p>
        <p v-if="d.offer.extra_cards" class="rounded-2xl bg-indigo-50 px-4 py-3 text-[15px] text-indigo-900">Il vous reste <b>{{ d.offer.extra_cards }} fiches IA</b> (photo + IA). Ajouter un produit à la main reste toujours gratuit.</p>

        <button type="button" class="tap flex w-full items-center justify-center gap-2 rounded-2xl border-2 border-dashed border-[var(--app-color)] bg-white py-3.5 text-[16px] font-bold text-[var(--app-color)]" @click="openNew">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 8h3l2-3h6l2 3h3v11H4z" /><circle cx="12" cy="13" r="3.5" /></svg>
          Nouveau produit : photo + IA
        </button>

        <label class="m-card flex items-center gap-2 px-3">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7" /><path d="M20 20l-3.5-3.5" /></svg>
          <input v-model="search" class="min-h-[46px] flex-1 bg-transparent text-[16px] outline-none" placeholder="Chercher dans mon catalogue" aria-label="Chercher un produit">
        </label>

        <div class="m-card divide-y divide-slate-100 overflow-hidden">
          <p class="px-4 py-2.5 text-[13px] font-semibold text-slate-500">MON CATALOGUE · {{ selected.length }} choisi{{ selected.length > 1 ? 's' : '' }}</p>
          <label v-for="p in shownCatalog" :key="p.id" :class="['flex min-h-[60px] items-center gap-3 px-4 py-2.5', p.stock === 0 ? 'opacity-50' : '']">
            <input type="checkbox" class="h-6 w-6 shrink-0 accent-[var(--app-color)]" :checked="isSelected(p.id)" :disabled="p.stock === 0" @change="toggle(p)">
            <img v-if="p.photo_url" :src="thumb(p.photo_url, 160)" alt="" class="h-12 w-12 shrink-0 rounded-xl object-cover">
            <span v-else class="grid h-12 w-12 shrink-0 place-items-center rounded-xl bg-red-50 text-[18px]" aria-hidden="true">📷</span>
            <span class="min-w-0 flex-1">
              <b class="block truncate text-[15px]">{{ p.name }}</b>
              <span v-if="!p.photo_url" class="mr-1.5 inline-block rounded-md bg-red-50 px-1.5 text-[12px] font-bold text-red-700">sans photo</span>
              <span class="text-[14px] text-slate-500">{{ p.stock === null ? 'stock non suivi' : p.stock === 0 ? 'Plus en stock' : `${p.stock} en stock` }} · {{ money(p.price) }}</span>
            </span>
          </label>
          <p v-if="!d.catalog.length" class="p-6 text-center text-[15px] text-slate-500">Votre catalogue est vide : ajoutez vos produits en photo.</p>
        </div>

        <!-- Produits du live : prix et quantité -->
        <div v-if="selected.length" class="m-card divide-y divide-slate-100">
          <p class="px-4 py-2.5 text-[13px] font-semibold text-slate-500">PRIX ET QUANTITÉ DU LIVE</p>
          <div v-for="(r, idx) in selected" :key="r.key" class="px-4 py-2.5">
            <!-- Photo obligatoire : elle est enregistrée sur le produit du catalogue -->
            <div v-if="!r.photo_url && isFirstOf(idx)" class="mb-2 rounded-xl bg-red-50 p-2.5 text-red-800">
              <p class="text-[14px] font-bold">📷 Photo manquante : elle est obligatoire pour la carte.</p>
              <p v-if="uploadingFor === r.product_id" class="mt-1.5 text-[14px] font-semibold">Envoi de la photo…</p>
              <div v-else class="mt-1.5 grid grid-cols-2 gap-2">
                <label class="tap flex cursor-pointer items-center justify-center rounded-lg bg-white py-2 text-[14px] font-bold text-slate-800 ring-1 ring-red-200">
                  Prendre la photo
                  <input type="file" accept="image/*" capture="environment" class="sr-only" @change="productPhoto(r.product_id, $event)">
                </label>
                <label class="tap flex cursor-pointer items-center justify-center rounded-lg bg-white py-2 text-[14px] font-bold text-slate-800 ring-1 ring-red-200">
                  Depuis la galerie
                  <input type="file" accept="image/*" class="sr-only" @change="productPhoto(r.product_id, $event)">
                </label>
              </div>
            </div>
            <div class="flex items-center gap-2">
              <img v-if="r.photo_url && isFirstOf(idx)" :src="thumb(r.photo_url, 160)" alt="" class="h-9 w-9 shrink-0 rounded-lg object-cover">
              <span class="min-w-0 flex-1 truncate text-[15px] font-semibold">{{ r.name }}</span>
              <label class="sr-only" :for="`price-${r.key}`">Prix de {{ r.name }}</label>
              <input :id="`price-${r.key}`" v-model.number="r.price" type="number" min="0" step="500" class="m-input !min-h-[42px] w-28 text-right">
              <label class="sr-only" :for="`qty-${r.key}`">Quantité de {{ r.name }}</label>
              <input :id="`qty-${r.key}`" v-model.number="r.quantity" type="number" min="1" class="m-input !min-h-[42px] w-16 text-right">
            </div>
            <!-- Tailles ou couleurs : chacune a son code sur la carte -->
            <div v-if="hasVariants(r.product_id)" class="mt-1.5 flex items-center gap-2">
              <input v-model="r.variant" maxlength="40" class="m-input !min-h-[42px] flex-1" placeholder="Taille ou couleur (ex. M, Rouge)" :aria-label="`Taille ou couleur de ${r.name}`">
              <button type="button" class="grid h-[42px] w-[42px] shrink-0 place-items-center rounded-xl bg-slate-100 text-[18px] text-slate-600" :aria-label="`Retirer cette taille de ${r.name}`" @click="removeRow(idx)">✕</button>
            </div>
            <button v-if="isLastOf(idx)" type="button" class="mt-1.5 text-[15px] font-bold text-[var(--app-color)]" @click="addVariant(idx)">＋ Taille ou couleur</button>
          </div>
        </div>
        </template>

        <!-- ── Nouveau produit : photo + IA ── -->
        <template v-if="panel === 'nouveau'">
        <div class="overflow-hidden rounded-[22px] bg-slate-100">
          <img v-if="preview" :src="preview" alt="Photo du produit" class="h-52 w-full object-cover">
          <div class="grid grid-cols-2 gap-2 p-2">
            <label class="tap flex cursor-pointer flex-col items-center justify-center gap-1.5 rounded-2xl bg-white py-4 text-[15px] font-bold text-slate-700 shadow-sm">
              <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 8h3l2-3h6l2 3h3v11H4z" /><circle cx="12" cy="13" r="3.5" /></svg>
              {{ preview ? 'Reprendre la photo' : 'Prendre la photo' }}
              <input type="file" accept="image/*" capture="environment" class="sr-only" @change="onPhoto">
            </label>
            <label class="tap flex cursor-pointer flex-col items-center justify-center gap-1.5 rounded-2xl bg-white py-4 text-[15px] font-bold text-slate-700 shadow-sm">
              <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2" /><circle cx="9" cy="10" r="2" /><path d="m21 16-5-5-9 9" /></svg>
              Depuis la galerie
              <input type="file" accept="image/*" class="sr-only" @change="onPhoto">
            </label>
          </div>
        </div>

        <!-- Photo choisie : l'IA ne travaille que si on le demande (une fiche IA), sinon tout à la main, gratuit -->
        <div v-if="preview && !ai && !recognizing && !needPack" class="rounded-2xl bg-indigo-50 px-4 py-3 text-[15px] text-indigo-900">
          <template v-if="cardsLeft > 0">
            <button type="button" class="block w-full rounded-xl bg-indigo-600 py-3 text-[16px] font-bold text-white" @click="analyze">✨ Remplir avec l'IA (1 fiche)</button>
            <span class="mt-1.5 block text-center text-[14px]">Il vous reste {{ cardsLeft }} fiche{{ cardsLeft > 1 ? 's' : '' }} IA. Ou écrivez le nom vous-même ci-dessous, c'est gratuit.</span>
          </template>
          <template v-else>
            <span class="block font-bold">Vous n'avez plus de fiches IA.</span>
            <span class="mt-1 block">Pas obligé d'en acheter : écrivez le nom ci-dessous, c'est gratuit.</span>
            <button type="button" class="mt-2 block w-full rounded-xl bg-amber-600 py-2.5 font-bold text-white" @click="buyPack">Acheter {{ d.offer.prices.pack_cards }} fiches pour {{ money(d.offer.prices.pack) }}</button>
          </template>
        </div>
        <div v-if="recognizing" class="rounded-2xl bg-indigo-50 px-4 py-3 text-[15px] font-semibold text-indigo-800">L'IA regarde la photo…</div>
        <div v-else-if="ai" class="rounded-2xl border-2 border-indigo-500 bg-indigo-50 px-4 py-3">
          <p class="text-[14px] font-bold text-indigo-700">L'IA a reconnu le produit</p>
          <p class="text-[18px] font-extrabold text-indigo-950">{{ ai.name }}</p>
          <p class="text-[14px] text-indigo-800">Fiches IA restantes : {{ ai.cards.left }}</p>
          <button v-if="np.token" type="button" class="mt-1 text-[14px] font-bold text-indigo-700 underline" @click="analyze">Pas le bon nom ? Analyser de nouveau (gratuit)</button>
        </div>
        <div v-if="needPack" class="rounded-2xl bg-amber-50 px-4 py-3 text-[15px] text-amber-900">
          Vous n'avez plus de fiches IA.
          <span class="mt-1 block font-bold">Pas obligé d'en acheter : écrivez le nom ci-dessous, c'est gratuit.</span>
          <button type="button" class="mt-2 block w-full rounded-xl bg-amber-600 py-2.5 font-bold text-white" @click="buyPack">Acheter {{ d.offer.prices.pack_cards }} fiches pour {{ money(d.offer.prices.pack) }}</button>
          <span class="mt-1 block text-[13px]">Les fiches achetées restent valables pour vos prochains lives.</span>
        </div>

        <div class="space-y-3">
          <label class="block text-[14px] font-semibold text-slate-600">Nom du produit
            <input v-model="np.name" class="m-input mt-1 w-full">
          </label>
          <label class="block text-[14px] font-semibold text-slate-600">Description courte
            <textarea v-model="np.description" rows="2" class="m-input mt-1 w-full !py-2" />
          </label>
          <div class="grid grid-cols-3 gap-2">
            <label class="block text-[14px] font-semibold text-slate-600">Prix d'achat
              <input v-model.number="np.cost" type="number" min="0" step="500" class="m-input mt-1 w-full">
            </label>
            <label class="block text-[14px] font-semibold text-slate-600">Prix du live
              <input v-model.number="np.price" type="number" min="0" step="500" class="m-input mt-1 w-full !ring-2 !ring-slate-900">
            </label>
            <label class="block text-[14px] font-semibold text-slate-600">Quantité
              <input v-model.number="np.quantity" type="number" min="1" class="m-input mt-1 w-full">
            </label>
          </div>
          <p v-if="np.price && np.cost" :class="['text-[15px]', np.price > np.cost ? 'text-slate-700' : 'text-red-700']">
            <b>Il vous reste {{ money(np.price - np.cost) }} par pièce.</b>
            <template v-if="ai?.price_range"> Vendu entre {{ money(ai.price_range.min) }} et {{ money(ai.price_range.max) }} dans d'autres boutiques.</template>
          </p>
          <label class="flex items-center gap-2 text-[15px]"><input v-model="np.keep_in_catalog" type="checkbox" class="h-5 w-5"> Garder aussi dans mon catalogue</label>
        </div>
        </template>

        <!-- ── L'étiquette à télécharger ── -->
        <template v-if="panel === 'etiquette'">
          <button type="button" class="tap flex w-full items-center gap-3 rounded-2xl bg-slate-900 p-3 text-left text-white" @click="videoOpen = true">
            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-white/15 text-[20px]" aria-hidden="true">▶</span>
            <span class="min-w-0"><b class="block text-[16px]">Voir la vidéo de l'étiquette</b><span class="text-[13.5px] text-slate-300">Télécharger, publier, épingler : en moins d'une minute</span></span>
          </button>

          <label class="block text-[14px] font-semibold text-slate-600">Nom du live
            <input v-model="liveName" class="m-input mt-1 w-full !text-[17px] !font-semibold" @change="saveItems">
          </label>
          <div class="flex rounded-2xl bg-slate-200 p-1" role="radiogroup" aria-label="Format de l'étiquette">
            <button type="button" role="radio" :aria-checked="!square" :class="['tap flex-1 rounded-xl py-2.5 text-[15px] font-bold', !square ? 'bg-white shadow-sm' : 'text-slate-600']" @click="square = false">TikTok</button>
            <button type="button" role="radio" :aria-checked="square" :class="['tap flex-1 rounded-xl py-2.5 text-[15px] font-bold', square ? 'bg-white shadow-sm' : 'text-slate-600']" @click="square = true">Facebook</button>
          </div>
          <div class="space-y-3">
            <div v-for="c in cards" :key="c.letter">
              <img v-if="c.url" :src="c.url" :alt="`Étiquette ${c.letter} du live`" class="mx-auto w-full max-w-[300px] rounded-2xl shadow-soft">
              <p v-else class="m-card p-6 text-center text-slate-500">Préparation de l'étiquette {{ c.letter }}…</p>
            </div>
          </div>
          <button type="button" class="m-btn-primary w-full" :disabled="saving || !cards.every((c) => c.canvas)" @click="downloadCards">⬇ Télécharger l'étiquette{{ cards.length > 1 ? `s (${cards.length})` : '' }}</button>

          <section class="space-y-3 rounded-2xl bg-indigo-50 p-4">
            <div class="flex gap-3"><b class="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-[var(--app-color)] text-[14px] text-white">1</b><p class="text-[15.5px]">Publiez l'étiquette sur {{ square ? 'Facebook' : 'TikTok' }} et <b>épinglez-la</b> sur votre profil.</p></div>
            <div class="flex gap-3"><b class="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-[var(--app-color)] text-[14px] text-white">2</b>
              <div class="min-w-0 flex-1 space-y-1.5">
                <p class="text-[15.5px]">Mettez le <b>lien de votre live</b> dans votre bio : vos clients peuvent aussi commander là.</p>
                <div class="flex items-center gap-2 rounded-xl bg-white px-3 py-2 ring-1 ring-slate-200"><span class="min-w-0 flex-1 truncate text-[14px]">{{ d.public_url }}</span><button type="button" class="shrink-0 text-[14px] font-black text-[var(--app-color)]" @click="copyLink">{{ copied ? 'Copié ✓' : 'Copier' }}</button></div>
              </div>
            </div>
            <div class="flex gap-3"><b class="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-[var(--app-color)] text-[14px] text-white">3</b><p class="text-[15.5px]">Pendant le live, épinglez votre <b>numéro WhatsApp</b> en commentaire<template v-if="d.offer.phone"> : <b class="whitespace-nowrap">{{ localPhone(d.offer.phone) }}</b></template>. Les clients y envoient le code du produit.</p></div>
          </section>
        </template>
      </div>

      <!-- Bas de la fenêtre -->
      <footer v-if="panel !== 'whatsapp'" class="shrink-0 border-t border-slate-200 bg-white px-4 pt-3 pb-[max(.75rem,env(safe-area-inset-bottom))]">
        <template v-if="panel === 'produits'">
          <p v-if="noPhoto.length" class="mb-2 text-center text-[14px] font-semibold text-red-700">{{ noPhoto.length }} produit{{ noPhoto.length > 1 ? 's' : '' }} sans photo : ajoutez {{ noPhoto.length > 1 ? 'leur' : 'sa' }} photo pour l'étiquette.</p>
          <button type="button" class="m-btn-primary w-full" :disabled="!selected.length || noPhoto.length > 0 || saving" @click="validateItems">Valider {{ selected.length }} produit{{ selected.length > 1 ? 's' : '' }}</button>
        </template>
        <template v-else-if="panel === 'nouveau'">
          <p v-if="!photoFile" class="mb-2 text-center text-[14px] font-semibold text-red-700">📷 Prenez ou choisissez d'abord la photo : elle est obligatoire pour l'étiquette.</p>
          <div class="grid grid-cols-2 gap-2">
            <button type="button" class="m-btn-secondary" :disabled="!photoFile || !np.name || !np.price || !np.quantity || saving" @click="saveProduct(true)">Ajouter + un autre</button>
            <button type="button" class="m-btn-primary" :disabled="!photoFile || !np.name || !np.price || !np.quantity || saving" @click="saveProduct(false)">Ajouter au live</button>
          </div>
        </template>
        <button v-else-if="panel === 'etiquette'" type="button" class="w-full rounded-2xl bg-emerald-600 py-3.5 text-[17px] font-black text-white disabled:opacity-60" :disabled="saving" @click="labelReady">C'est fait ✓</button>
      </footer>
    </div>

    <!-- Vidéo de l'étiquette -->
    <div v-if="videoOpen" class="fixed inset-0 z-[60] flex flex-col items-center justify-center bg-black/90 p-4" role="dialog" aria-modal="true" aria-label="Vidéo de l'étiquette" @click.self="videoOpen = false">
      <button type="button" class="mb-3 self-end rounded-full bg-white/15 px-4 py-2 text-[15px] font-bold text-white" @click="videoOpen = false">Fermer ✕</button>
      <video class="max-h-[80dvh] w-full max-w-[420px] rounded-2xl bg-black" controls autoplay playsinline :poster="`${LABEL_VIDEO}.jpg`" :src="`${LABEL_VIDEO}.mp4`" />
    </div>
  </div>
</template>

<script setup>
import LivePaySheet from './LivePaySheet.vue'
import LiveTiers from './LiveTiers.vue'
import { thumb } from '../../../utils/thumb'
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../../../bootstrap/axios'
import { useToastStore } from '../../../stores/toasts'
import { localPhone, money } from '../../../utils/format'
import { drawCard, saveCard } from './liveCard'
import LiveIntro from './LiveIntro.vue'
import { confirmDialog } from '../../../composables/useDialog'
import { isPhone } from '../../../composables/usePhone'
import LiveWhatsAppConnect from './LiveWhatsAppConnect.vue'

/**
 * Préparer mon live, sur une seule page (présentation de Jibiat Marchand) : WhatsApp, produits (catalogue ou
 * photo + IA), étiquette à télécharger, puis « Je commence mon live ». Chaque étape s'ouvre en grand et se coche.
 */
const LABEL_VIDEO = '/videos/live/live-etiquette-v'
// Dans l'onglet Live du téléphone (page à glisser) : rien de fixé en bas de l'écran
defineProps({ embedded: Boolean })
const router = useRouter()
const toasts = useToastStore()
const d = ref(null)
const error = ref('')
const panel = ref(null)
const videoOpen = ref(false)
const copied = ref(false)
const search = ref('')
const selected = ref([])
const saving = ref(false)
const liveName = ref('')
const square = ref(false)
const cards = ref([])

// ── Étapes et leur état ──
const store = {
  get(key) {
    try {
      return localStorage.getItem(key)
    } catch {
      return null
    }
  },
  set(key, value) {
    try {
      localStorage.setItem(key, value)
    } catch {
      // stockage impossible : l'étape restera à refaire sur ce téléphone
    }
  },
}
// WhatsApp proposé (connexion officielle ou option de la console), relié ou non
// Étape WhatsApp toujours présente (comme Jibiat Marchand) : connexion, ou « Continuer sans WhatsApp » avec le lien du live
const waOffered = computed(() => !!d.value)
// Seule la connexion officielle (Facebook) est proposée ; un numéro déjà relié autrement reste relié
const waConnectable = computed(() => !!(d.value?.whatsapp?.auto || d.value?.whatsapp?.official?.available))
// « Continuer sans WhatsApp » : retenu pour ce live, sur ce téléphone
const skipped = ref(false)
const skipKey = () => `live-sans-whatsapp-${d.value?.live?.id}`
function skipWhatsApp() {
  store.set(skipKey(), '1')
  skipped.value = true
  panel.value = null
}
// Étiquette faite pour ces produits-là (codes, prix et quantités) : un changement de produits la redemande

const waDone = computed(() => !!d.value?.whatsapp?.auto || skipped.value)
const itemsDone = computed(() => !!d.value?.items?.length && d.value.items.every((i) => i.photo_url))
// Étiquette faite pour ces produits-là (enregistré sur le serveur) : un changement de produits la redemande
const labelDone = computed(() => itemsDone.value && !!d.value?.label_ready)
const current = computed(() => {
  if (waOffered.value && !waDone.value) return 'whatsapp'
  if (!itemsDone.value) return 'produits'
  if (!labelDone.value) return 'etiquette'
  return 'start'
})
const stepList = computed(() => {
  const items = d.value?.items || []
  const codes = items.length ? `${items.length} produit${items.length > 1 ? 's' : ''} · ${items[0].code}${items.length > 1 ? ` à ${items[items.length - 1].code}` : ''}` : ''
  const pieces = items.reduce((n, i) => n + Number(i.quantity || 0), 0)
  const missing = items.filter((i) => !i.photo_url).length
  const list = []
  if (waOffered.value) {
    list.push({
      key: 'whatsapp', panel: 'whatsapp', again: d.value.whatsapp?.auto ? 'Changer' : 'Revoir',
      title: d.value.whatsapp?.auto ? 'WhatsApp connecté' : skipped.value ? 'Sans WhatsApp : le lien du live' : 'Connectez votre WhatsApp',
      text: d.value.whatsapp?.auto
        ? `📱 ${waPhone.value ? localPhone(waPhone.value) : 'Numéro relié'} · réponses gratuites`
        : skipped.value ? 'Les clients commandent par le lien' : 'Pour recevoir les commandes',
    })
  }
  list.push({
    key: 'produits', panel: 'produits', again: 'Modifier',
    title: items.length ? codes : 'Choisissez vos produits',
    text: missing ? `📷 ${missing} produit${missing > 1 ? 's' : ''} sans photo` : items.length ? `${pieces} pièce${pieces > 1 ? 's' : ''} · toutes avec photo` : 'Dans votre catalogue, ou un nouveau en photo',
    alert: missing > 0,
  })
  list.push({
    key: 'etiquette', panel: 'etiquette', again: 'Revoir',
    title: labelDone.value ? 'Étiquette publiée' : 'L\'étiquette à télécharger',
    text: labelDone.value ? 'Épinglée, lien dans la bio ✓' : 'Pour TikTok ou Facebook, à épingler',
  })
  list.push({ key: 'start', title: 'Commencez votre live', text: 'Lancez-le sur TikTok ou Facebook, puis touchez le bouton ici' })
  const order = list.map((s) => s.key)
  const at = order.indexOf(current.value)
  return list.map((s, i) => ({ ...s, state: i < at ? 'done' : i === at ? 'current' : 'todo' }))
})
const panelTitle = computed(() => ({
  whatsapp: `${stepNumber('whatsapp')} · Votre WhatsApp du live`,
  produits: `${stepNumber('produits')} · Les produits du live`,
  nouveau: 'Nouveau produit : photo + IA',
  etiquette: `${stepNumber('etiquette')} · L'étiquette à épingler`,
})[panel.value] || '')
const stepNumber = (key) => stepList.value.findIndex((s) => s.key === key) + 1

async function open(name) {
  panel.value = name
  if (name === 'etiquette') await renderCards()
}
function closePanel() {
  panel.value = null
}
async function labelReady() {
  saving.value = true
  try {
    fill((await http.post(`/live/${d.value.live.id}/label-ready`)).data.data)
    panel.value = null
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    saving.value = false
  }
}

// Reprendre un live passé : ses produits, leur ordre, leurs prix et leurs quantités
const pastOpen = ref(false)
async function reuse(past) {
  if (selected.value.length && !(await confirmDialog(`Remplacer les ${selected.value.length} produits choisis par ceux de « ${past.name} » ?`))) return
  saving.value = true
  try {
    fill((await http.post(`/live/${d.value.live.id}/copy/${past.id}`)).data.data)
    pastOpen.value = false
    toasts.success(`Produits de « ${past.name} » repris. Vérifiez les prix et les quantités.`)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    saving.value = false
  }
}
const pastDay = (v) => (v ? new Date(v).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' }) : '')
async function copyLink() {
  try {
    await navigator.clipboard.writeText(d.value.public_url)
    copied.value = true
    setTimeout(() => { copied.value = false }, 2500)
  } catch {
    toasts.error('Copie impossible : appuyez longtemps sur le lien pour le copier.')
  }
}
const shownCatalog = computed(() => {
  const q = search.value.trim().toLowerCase()
  return (d.value?.catalog || []).filter((p) => !q || p.name.toLowerCase().includes(q))
})
const isSelected = (id) => selected.value.some((r) => r.product_id === id)
// Recharge du crédit live ou forfait du mois (PayDunya)
const paySheet = ref(false)
const priceLine = computed(() => {
  const o = d.value?.offer
  if (!o) return ''
  if (o.next_plan === 'free') return 'Les lives sont gratuits en ce moment.'
  if (o.offered_by_company) return 'Ce live vous est offert par votre agence.'
  if (o.monthly_until) return 'Compris dans votre forfait du mois.'
  if (!o.can_start) return `Rechargez au moins ${money(o.min_credit)} pour démarrer : vous payez à la fin, selon vos commandes.`
  if (o.free_left) return `Vous payez à la fin, selon vos commandes. Encore ${o.free_left} commandes offertes.`
  return o.pay_online
    ? 'Vous payez à la fin, selon vos commandes : pris sur votre crédit live.'
    : 'Vous payez à la fin, selon vos commandes : retenu sur votre prochain reversement.'
})
// Forfait du mois proposé quand les lives sont payés au palier
const offerMonthly = computed(() => {
  const o = d.value?.offer
  return !!o && o.next_plan === 'tier' && Number(o.prices?.month) > 0
})
async function subscribeMonthly() {
  saving.value = true
  try {
    const data = (await http.post('/live/subscribe')).data.data
    if (data.payment_url) {
      window.location.assign(data.payment_url)
      return
    }
    d.value.offer = data
    toasts.success('Forfait du mois activé : lives sans limite pendant 30 jours.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    saving.value = false
  }
}

function fill(data) {
  // Compteur de « Comment ça marche » : seulement dans la réponse de « prepare »
  data.intro ??= d.value?.intro
  d.value = data
  liveName.value = data.live.name
  selected.value = data.items.map((i) => row({ product_id: i.product_id, name: i.name, variant: i.variant || '', price: i.price, quantity: i.quantity, code: i.code, photo_url: i.photo_url }))
  // Live en cours : bandeau seulement (plus d'ouverture automatique de l'écran du live)
}

// « Comment ça marche » en entier aux premières visites (compté sur le serveur, par compte)
const introOpen = ref(false)
const running = computed(() => d.value?.live?.status === 'live')
// Numéro qui reçoit les commandes (connexion officielle, sinon code d'association)
const waPhone = computed(() => d.value?.whatsapp?.official?.phone || d.value?.whatsapp?.phone || null)
let introChecked = false
async function closeIntro() {
  introOpen.value = false
  window.scrollTo({ top: 0 })
  try {
    d.value.intro = (await http.post('/live/intro-seen')).data.data
  } catch {
    // compteur non enregistré : la page reviendra à la prochaine visite
  }
}

async function load() {
  try {
    fill((await http.post('/live/prepare')).data.data)
    if (!introChecked) {
      introChecked = true
      introOpen.value = !!d.value.intro?.full
      skipped.value = store.get(skipKey()) === '1'
    }
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

let rowKey = 0
const row = (r) => ({ ...r, key: ++rowKey })

function toggle(p) {
  selected.value = isSelected(p.id)
    ? selected.value.filter((r) => r.product_id !== p.id)
    : [...selected.value, row({ product_id: p.id, name: p.name, variant: '', price: p.price, quantity: p.stock || 1, photo_url: p.photo_url })]
}

// Variantes : une ligne par taille ou couleur, juste sous le produit
const hasVariants = (productId) => selected.value.filter((r) => r.product_id === productId).length > 1
const isFirstOf = (idx) => selected.value[idx - 1]?.product_id !== selected.value[idx].product_id
// Produits choisis sans photo : la carte ne peut pas être créée
const noPhoto = computed(() => [...new Set(selected.value.filter((r) => !r.photo_url).map((r) => r.product_id))])
const uploadingFor = ref(null)

async function productPhoto(productId, event) {
  const file = event.target.files?.[0]
  event.target.value = ''
  if (!file) return
  uploadingFor.value = productId
  try {
    const body = new FormData()
    body.append('photo', await shrinkPhoto(file))
    const { photo_url: url } = (await http.post(`/live/products/${productId}/photo`, body)).data.data
    for (const r of selected.value) if (r.product_id === productId) r.photo_url = url
    const p = d.value.catalog.find((c) => c.id === productId)
    if (p) p.photo_url = url
    toasts.success('Photo ajoutée.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    uploadingFor.value = null
  }
}
const isLastOf = (idx) => selected.value[idx + 1]?.product_id !== selected.value[idx].product_id
function addVariant(idx) {
  const r = selected.value[idx]
  selected.value.splice(idx + 1, 0, row({ ...r, variant: '', quantity: 1 }))
}
function removeRow(idx) {
  selected.value.splice(idx, 1)
}

async function saveItems() {
  const { data } = await http.put(`/live/${d.value.live.id}/items`, {
    name: liveName.value,
    items: selected.value.map((r) => ({ product_id: r.product_id, variant: hasVariants(r.product_id) ? r.variant.trim() || null : null, price: r.price, quantity: r.quantity })),
  })
  fill(data.data)
}

async function validateItems() {
  if (noPhoto.value.length) {
    toasts.error('Ajoutez la photo de chaque produit pour créer la carte.')
    return
  }
  const missing = selected.value.find((r) => hasVariants(r.product_id) && !r.variant.trim())
  if (missing) {
    toasts.error(`${missing.name} : écrivez la taille ou la couleur de chaque ligne.`)
    return
  }
  saving.value = true
  try {
    await saveItems()
    panel.value = null
    window.scrollTo({ top: 0 })
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    saving.value = false
  }
}

// Une étiquette par lettre (A1…A9, B1…)
async function renderCards() {
  const groups = {}
  for (const i of d.value.items) (groups[i.code[0]] ||= []).push(i)
  cards.value = Object.keys(groups).map((letter) => ({ letter, url: null, canvas: null }))
  for (const c of cards.value) {
    c.canvas = await drawCard({ items: groups[c.letter], square: square.value })
    c.url = c.canvas.toDataURL('image/png')
  }
}
watch(square, () => { if (panel.value === 'etiquette') renderCards() })

// WhatsApp relié : mise à jour de l'écran
async function onWhatsApp() {
  await load()
  if (panel.value === 'whatsapp' && d.value.whatsapp?.auto) panel.value = null
}

async function downloadCards() {
  saving.value = true
  try {
    for (const c of cards.value) await saveCard(c.canvas, `live-etiquette-${c.letter}.png`)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    saving.value = false
  }
}

async function start() {
  saving.value = true
  try {
    const { data } = await http.post(`/live/${d.value.live.id}/start`)
    router.push(`/marchand/live/${data.data.live.id}`)
  } catch (e) {
    // Ni commandes offertes ni crédit suffisant : recharger (ou forfait du mois)
    if (e.response?.data?.errors?.payment) {
      paySheet.value = true
      return
    }
    toasts.error(apiErrorMessage(e))
  } finally {
    saving.value = false
  }
}

// ── Nouveau produit ──
const preview = ref(null)
const photoFile = ref(null)
const ai = ref(null)
const recognizing = ref(false)
const needPack = ref(false)
const np = reactive({ name: '', description: '', category: '', cost: null, price: null, quantity: 1, keep_in_catalog: true, token: null })

function openNew() {
  Object.assign(np, { name: '', description: '', category: '', cost: null, price: null, quantity: 1, keep_in_catalog: true, token: null })
  preview.value = null
  photoFile.value = null
  ai.value = null
  needPack.value = false
  panel.value = 'nouveau'
}

async function onPhoto(event) {
  const file = event.target.files?.[0]
  // Le même fichier pourra être choisi de nouveau
  event.target.value = ''
  if (!file) return
  photoFile.value = await shrinkPhoto(file)
  preview.value = URL.createObjectURL(photoFile.value)
  // Nouvelle photo : rien n'est analysé tant que la vendeuse ne le demande pas
  ai.value = null
  needPack.value = false
}

/**
 * Photo réduite (1600 px au plus, JPEG) : envoi plus rapide sur le réseau mobile, et l'IA refuse les images
 * trop lourdes des téléphones récents. En cas de souci, la photo d'origine.
 */
async function shrinkPhoto(file, max = 1600) {
  try {
    const img = await createImageBitmap(file, { imageOrientation: 'from-image' })
    const k = Math.min(1, max / Math.max(img.width, img.height))
    if (k === 1 && file.size < 1_500_000) return file
    const canvas = document.createElement('canvas')
    canvas.width = Math.round(img.width * k)
    canvas.height = Math.round(img.height * k)
    canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height)
    const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.85))
    return blob ? new File([blob], 'produit.jpg', { type: 'image/jpeg' }) : file
  } catch {
    return file
  }
}

// Fiches IA restantes (fiches offertes, recharges, forfait)
const cardsLeft = computed(() => d.value?.cards?.left ?? 0)

async function analyze() {
  if (!photoFile.value) return
  recognizing.value = true
  needPack.value = false
  try {
    const body = new FormData()
    body.append('photo', photoFile.value)
    if (np.token) body.append('token', np.token)
    const { data } = await http.post(`/live/${d.value.live.id}/recognize`, body)
    ai.value = data.data
    if (data.data.cards) d.value.cards = data.data.cards
    Object.assign(np, { name: data.data.name, description: data.data.description, category: data.data.category, token: data.data.token })
  } catch (e) {
    const message = apiErrorMessage(e)
    if (e.response?.data?.errors?.cards) needPack.value = true
    else toasts.error(message)
  } finally {
    recognizing.value = false
  }
}

async function buyPack() {
  try {
    const data = (await http.post('/live/pack')).data.data
    if (data.payment_url) {
      window.location.assign(data.payment_url)
      return
    }
    d.value.offer = data
    d.value.cards = { ...d.value.cards, left: (d.value.cards?.left ?? 0) + Number(data.prices?.pack_cards ?? 0) }
    needPack.value = false
    toasts.success('Fiches ajoutées. Touchez « Remplir avec l\'IA ».')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function saveProduct(another) {
  saving.value = true
  try {
    await saveItems()
    const body = new FormData()
    for (const k of ['name', 'description', 'category', 'price', 'quantity']) if (np[k] !== null && np[k] !== '') body.append(k, np[k])
    body.append('keep_in_catalog', np.keep_in_catalog ? '1' : '0')
    if (photoFile.value) body.append('photo', photoFile.value)
    const { data } = await http.post(`/live/${d.value.live.id}/products`, body)
    fill(data.data)
    toasts.success(`${np.name} ajouté au live.`)
    if (another) openNew()
    else panel.value = 'produits'
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>
