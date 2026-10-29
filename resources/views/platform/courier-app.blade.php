@php
    // Sur l'adresse d'une entreprise de livraison : son nom en titre
    $company = rescue(fn () => \App\Support\Branding::company(), null, false);
    $name = $company?->name ?? 'Jibiat';
    $size = $app ? number_format($app['size'] / 1048576, 1, ',', ' ').' Mo' : null;
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Application Jibiat Livreur · {{ $name }}</title>
    <meta name="robots" content="noindex">
    <style>
        :root { color-scheme: light; }
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; background: #f1f5f9; color: #0f172a; line-height: 1.55; }
        main { max-width: 32rem; margin: 0 auto; padding: 2rem 1rem 3rem; }
        h1 { font-size: 1.6rem; margin: 0 0 .25rem; }
        h2 { font-size: 1.1rem; margin: 1.75rem 0 .5rem; }
        p, li { color: #334155; }
        ol { padding-left: 1.25rem; }
        li { margin: .35rem 0; }
        .muted { color: #475569; font-size: .9rem; }
        .card { background: #fff; border-radius: 1rem; padding: 1.25rem; box-shadow: 0 1px 3px rgb(0 0 0 / .08); }
        .btn { display: block; text-align: center; background: #047857; color: #fff; font-weight: 700; font-size: 1.1rem; padding: 1rem; border-radius: .9rem; text-decoration: none; margin-top: 1rem; }
        .btn:hover { background: #065f46; }
        .note { background: #fffbeb; border: 1px solid #fcd34d; border-radius: .75rem; padding: .75rem 1rem; color: #78350f; }
        a { color: #047857; }
    </style>
</head>
<body>
<main>
    <h1>Jibiat Livreur</h1>
    <p class="muted">Application Android des livreurs{{ $company ? ' de '.$company->name : '' }}</p>

    <div class="card">
        <p>Avec l'application, votre téléphone <strong>sonne comme un appel</strong> à chaque nouveau ramassage ou nouvelle
            livraison, même écran verrouillé. Vous ne ratez plus aucune mission.</p>
        @if ($app)
            <a class="btn" href="{{ url('/app/livreur.apk') }}" download>Télécharger l'application</a>
            <p class="muted" style="text-align:center;margin:.5rem 0 0">Version {{ $app['version_name'] }} · {{ $size }} · Android</p>
        @else
            <p class="note">L'application sera bientôt disponible ici. Revenez dans quelques heures.</p>
        @endif
    </div>

    <h2>Installer en 4 étapes</h2>
    <ol>
        <li>Touchez <strong>« Télécharger l'application »</strong> depuis le téléphone du livreur.</li>
        <li>Ouvrez le fichier téléchargé (notification « Téléchargement terminé », ou dossier Téléchargements).</li>
        <li>Si Android demande d'autoriser l'installation, touchez <strong>Paramètres</strong>, activez
            <strong>« Autoriser cette source »</strong>, puis revenez en arrière. C'est à faire une seule fois.</li>
        <li>Touchez <strong>Installer</strong>, puis <strong>Ouvrir</strong>. Entrez le code de votre entreprise et
            connectez-vous avec votre numéro.</li>
    </ol>

    <h2>À la première ouverture</h2>
    <p>L'écran « Ne ratez aucune mission » demande trois autorisations : notifications, affichage sur l'écran verrouillé
        et mode « Ne pas déranger ». Acceptez-les, puis touchez <strong>« Tester la sonnerie »</strong>.</p>

    <h2>Mises à jour</h2>
    <p>Quand une nouvelle version sort, l'application l'annonce. Revenez sur cette page et installez-la par-dessus :
        vos réglages et votre compte sont gardés.</p>

    <p class="muted" style="margin-top:2rem">iPhone : utilisez Jibiat dans le navigateur ; les missions arrivent en
        notification, sans sonnerie d'appel.</p>
</main>
</body>
</html>
