<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Sécuriser mon compte · Jibiat</title>
    <style>
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; background: #f8fafc; color: #0f172a; line-height: 1.5; }
        main { max-width: 28rem; margin: 0 auto; padding: 2.5rem 1rem; }
        h1 { font-size: 1.5rem; margin: 0 0 1rem; }
        .note { margin-top: 1.25rem; padding: 1rem; border-radius: .75rem; font-weight: 500; }
        .ok { background: #ecfdf5; color: #065f46; } .err { background: #fef2f2; color: #b91c1c; }
        label { display: block; margin-top: 1.25rem; font-weight: 500; }
        input { display: block; width: 100%; box-sizing: border-box; margin-top: .35rem; padding: .7rem; font-size: 1rem; border: 1px solid #cbd5e1; border-radius: .5rem; }
        button { display: block; width: 100%; margin-top: 1.25rem; padding: .85rem; font-size: 1rem; font-weight: 600; border-radius: .5rem; border: 0; cursor: pointer; background: #dc2626; color: #fff; }
        p.help { color: #475569; font-size: .95rem; }
    </style>
</head>
<body>
<main>
    <h1>Sécuriser mon compte</h1>
    @if ($done)
        <p class="note ok">✓ C'est fait, {{ $user->name }} : toutes les sessions sont fermées, les appareils retenus oubliés et votre nouveau mot de passe est enregistré. Reconnectez-vous à la console avec lui.</p>
    @else
        <p class="help">Bonjour {{ $user->name }}. Choisissez un nouveau mot de passe : toutes les sessions ouvertes avec votre compte seront fermées, et le code de connexion sera redemandé partout.</p>
        @isset($error)<p class="note err">{{ $error }}</p>@endisset
        <form method="post">
            <input type="hidden" name="_token" value="{{ csrf_token() }}">
            <label>Nouveau mot de passe (12 caractères au moins)
                <input type="password" name="password" autocomplete="new-password" minlength="12" required>
            </label>
            <label>Confirmez le mot de passe
                <input type="password" name="password_confirmation" autocomplete="new-password" minlength="12" required>
            </label>
            <button>Tout déconnecter et changer le mot de passe</button>
        </form>
    @endif
</main>
</body>
</html>
