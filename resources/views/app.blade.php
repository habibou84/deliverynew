@php
    // Application installable selon l'espace : livreur ou e-commerçant (l'admin n'est pas une PWA)
    $pwa = request()->is('livreur*') ? 'livreur' : (request()->is('admin*') ? null : 'marchand');
    $theme = ['livreur' => '#1d4ed8', 'marchand' => '#047857'][$pwa] ?? '#0f172a';
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="{{ $theme }}">
    <title>{{ config('app.name', 'Livraison') }}</title>
    @if ($pwa)
        <link rel="manifest" href="{{ route('pwa.manifest', $pwa, false) }}">
        <link rel="apple-touch-icon" href="/icons/{{ $pwa }}-apple-180.png">
        <link rel="icon" type="image/svg+xml" href="/icons/{{ $pwa }}.svg">
    @endif
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="{{ $pwa === 'livreur' ? 'Livreur' : 'Mes livraisons' }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-slate-50 text-slate-900 antialiased">
    <div id="app"></div>
</body>
</html>
