@php
    $operator = config('marketing.legal.name') ?: config('platform.operator');
    $email = config('platform.contact_email') ?: config('marketing.legal.email');
    // Sur l'adresse d'une entreprise de livraison : c'est elle qui tient le compte
    $company = rescue(fn () => \App\Support\Branding::company(), null, false);
    $name = $company?->name ?? $operator;
    $days = \App\Models\AccountDeletionRequest::DELAY_DAYS;
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Supprimer votre compte · {{ $name }}</title>
    <style>
        :root { color-scheme: light; }
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; background: #f8fafc; color: #0f172a; line-height: 1.6; }
        main { max-width: 46rem; margin: 0 auto; padding: 2.5rem 1rem 4rem; }
        h1 { font-size: 1.75rem; margin: 0 0 .25rem; }
        h2 { font-size: 1.15rem; margin: 2rem 0 .5rem; }
        p, li { color: #334155; }
        ul, ol { padding-left: 1.25rem; }
        .muted { color: #64748b; font-size: .9rem; }
        .card { background: #fff; border-radius: .75rem; padding: 1rem 1.25rem; box-shadow: 0 1px 3px rgb(0 0 0 / .08); }
        a { color: #1d4ed8; }
    </style>
</head>
<body>
<main>
    <h1>Supprimer votre compte</h1>
    <p class="muted">{{ $name }}@if ($company) · applications e-commerçant et livreur @endif</p>

    <div class="card">
        <p>
            Vous êtes e-commerçant ou livreur{{ $company ? ' chez '.$company->name : '' }} : vous pouvez supprimer votre compte
            vous-même, depuis l'application ou depuis le site, sans rien demander à personne.
        </p>
    </div>

    <h2>Comment faire</h2>
    <ol>
        <li>Connectez-vous à l'application (ou au site{{ $company ? ' '.$company->url() : '' }}).</li>
        <li>Ouvrez l'onglet <strong>Moi</strong> (votre profil).</li>
        <li>En bas de la page, touchez <strong>Supprimer mon compte</strong>, puis confirmez.</li>
    </ol>
    <p>
        Vous ne pouvez plus vous connecter ? Écrivez à votre entreprise de livraison @if ($company?->phone) ({{ $company->phone }})@endif
        @if ($email) ou à <a href="mailto:{{ $email }}?subject=Suppression%20de%20mon%20compte">{{ $email }}</a>@endif
        en indiquant votre numéro de téléphone.
    </p>

    <h2>Ce qui se passe ensuite</h2>
    <ul>
        <li>Votre compte est fermé tout de suite : vous êtes déconnecté sur tous vos appareils.</li>
        <li>L'entreprise de livraison est prévenue. Elle peut rouvrir le compte à votre demande pendant {{ $days }} jours
            (par exemple s'il reste de l'argent à vous reverser ou des colis en cours).</li>
        <li>Après {{ $days }} jours, vos données personnelles sont effacées : nom, numéro de téléphone, adresse e-mail et mot de passe.</li>
    </ul>

    <h2>Ce qui est gardé</h2>
    <p>
        Les courses, les encaissements, les reversements et la comptabilité restent, sous un nom anonyme : la loi impose de
        conserver ces écritures. Ils ne permettent plus de vous joindre.
    </p>

    <p class="muted">Voir aussi la <a href="/confidentialite">politique de confidentialité</a>.</p>
</main>
</body>
</html>
