@props(['name', 'title'])

{{-- Vidéo en boucle, sans le son, lancée seule (haut de page) : verticale sur téléphone, horizontale sur ordinateur.
     Une seule vidéo est téléchargée (celle de l'écran), et aucune en mode « économie de données » : l'image reste. --}}
<div {{ $attributes->class('relative') }}>
    <video
        data-loop-video
        data-v="/marketing/videos/{{ $name }}-v"
        data-w="/marketing/videos/{{ $name }}-w"
        class="mx-auto block w-full max-w-[22rem] rounded-[2rem] shadow-2xl ring-1 ring-white/10 sm:max-w-none sm:rounded-2xl"
        poster="/marketing/videos/{{ $name }}-v.jpg"
        width="1080" height="1920"
        muted loop playsinline preload="none"
        aria-label="Vidéo : {{ $title }}"
    ></video>
    <script>
        (function () {
            var video = document.currentScript.previousElementSibling;
            var wide = window.matchMedia('(min-width: 640px)').matches;
            var base = video.dataset[wide ? 'w' : 'v'];
            video.poster = base + '.jpg';
            if (wide) { video.width = 1920; video.height = 1080; }
            var c = navigator.connection || {};
            if (c.saveData || /(^|-)2g$/.test(c.effectiveType || '')) return;
            video.src = base + '.mp4';
            video.play().catch(function () {});
        })();
    </script>
</div>
