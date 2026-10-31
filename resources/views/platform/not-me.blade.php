<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Verrouiller mon compte · Jibiat</title>
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
    <h1>Verrouiller mon compte</h1>
    @if ($done)
        <p class="note ok">✓ C'est fait, {{ $user->name }} : votre compte est verrouillé. Toutes les sessions sont fermées, les appareils retenus oubliés et l'ancien mot de passe ne marche plus.</p>
        <p class="help">Pour le rouvrir, demandez à un autre propriétaire de la console de vous renvoyer une invitation (Console › Équipe). Si vous êtes le seul propriétaire, la personne qui gère le serveur peut vous donner un nouveau mot de passe.</p>
    @else
        <p class="help">Bonjour {{ $user->name }}. Si cette connexion n'était pas vous, verrouillez votre compte : toutes les sessions seront fermées et votre mot de passe effacé. Personne ne pourra plus entrer, vous non plus, jusqu'à la réouverture par un autre propriétaire.</p>
        <form method="post">
            <input type="hidden" name="_token" value="{{ csrf_token() }}">
            <button>Verrouiller mon compte</button>
        </form>
    @endif
</main>
</body>
</html>
