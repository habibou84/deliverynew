<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }} · Logiciel des entreprises de livraison</title>
    <style>
        body { margin: 0; font-family: system-ui, -apple-system, sans-serif; background: #f8fafc; color: #0f172a; }
        main { max-width: 40rem; margin: 0 auto; padding: 4rem 1.25rem; }
        h1 { font-size: 2rem; margin: 0 0 .5rem; }
        p { line-height: 1.6; color: #334155; }
        .card { background: #fff; border-radius: 1rem; padding: 1.5rem; box-shadow: 0 1px 3px rgb(0 0 0 / .08); margin-top: 1.5rem; }
        code { background: #f1f5f9; padding: .1rem .4rem; border-radius: .3rem; }
    </style>
</head>
<body>
<main>
    <h1>{{ config('app.name') }}</h1>
    <p>Le logiciel des entreprises de livraison : courses, livreurs, caisse, paie, marchands et suivi des colis.</p>
    <div class="card">
        <p><strong>Vous travaillez avec une entreprise de livraison ?</strong><br>
            Ouvrez l'adresse qu'elle vous a donnée, par exemple <code>votre-entreprise.{{ $domain }}</code>.</p>
        <p><strong>Vous êtes une entreprise de livraison ?</strong><br>
            Contactez-nous pour ouvrir votre espace.</p>
    </div>
</main>
</body>
</html>
