<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Connecter ma boutique · {{ $company->name }}</title>
    <style>
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; background: #f8fafc; color: #0f172a; line-height: 1.5; }
        main { max-width: 28rem; margin: 0 auto; padding: 2.5rem 1rem; }
        h1 { font-size: 1.5rem; margin: 0 0 1rem; }
        .note { margin-top: 1.25rem; padding: 1rem; border-radius: .75rem; font-weight: 500; }
        .ok { background: #ecfdf5; color: #065f46; } .err { background: #fef2f2; color: #b91c1c; } .info { background: #f1f5f9; color: #334155; }
        button { display: block; width: 100%; margin-top: 1rem; padding: .85rem; font-size: 1rem; font-weight: 600; border-radius: .5rem; border: 0; cursor: pointer; }
        .yes { background: #059669; color: #fff; } .no { background: #fff; color: #b91c1c; border: 1px solid #fecaca; }
        p.help { color: #475569; font-size: .95rem; }
    </style>
</head>
<body>
<main>
    <h1>Connecter ma boutique</h1>
    @if ($error)
        <p class="note err">{{ $error }}</p>
    @elseif ($done === true)
        <p class="note ok">✓ C'est fait : votre boutique « {{ $request->business_name }} » sur Jibiat Marchand est connectée à votre compte chez {{ $company->name }}. Vous pouvez lui confier vos colis depuis Jibiat Marchand.</p>
    @elseif ($done === false)
        <p class="note info">Connexion refusée : rien n'a été changé sur votre compte chez {{ $company->name }}.</p>
    @elseif ($request->status !== \App\Models\ConnectRequest::PENDING)
        <p class="note info">Cette demande a déjà été traitée.</p>
    @else
        <p class="help">Bonjour {{ $request->merchant?->contact_name ?: $request->merchant?->business_name }}. Votre boutique <strong>« {{ $request->business_name }} »</strong> sur Jibiat Marchand demande à confier ses colis à votre compte chez <strong>{{ $company->name }}</strong>.</p>
        <p class="help">Si c'est bien vous, confirmez : vos commandes Jibiat Marchand pourront partir chez {{ $company->name }} en un clic, et vous les suivrez au même endroit que vos autres courses. Si vous ne connaissez pas cette boutique, refusez.</p>
        <form method="post">
            <input type="hidden" name="_token" value="{{ csrf_token() }}">
            <button class="yes" name="answer" value="yes">Oui, c'est ma boutique</button>
            <button class="no" name="answer" value="no">Non, refuser</button>
        </form>
    @endif
</main>
</body>
</html>
