# Jibiat IA

Abonnement à part, en plus de la formule de l'entreprise : chaque entreprise garde sa formule et souscrit, si elle
veut, à l'intelligence artificielle de Jibiat.

## Niveaux (réglables dans la console › Jibiat IA)

| Niveau | Prix / mois | Courses / mois | Assistant de dispatch | Notes vocales | Plafond de coût |
|---|---|---|---|---|---|
| IA Essentiel | 15 000 F | 1 500 | 10 analyses / jour | 100 min | 12 000 F |
| IA Pro | 45 000 F | 5 000 | toutes les 30 min | 300 min | 35 000 F |
| IA Entreprise | 120 000 F | 15 000 | toutes les 30 min | 1 000 min | 95 000 F |

Réglages : course au-delà du volume (8 F), jours d'essai (14), mois payés à l'année (10 sur 12), cours du dollar
(600 F) pour le coût réel. Stockés dans `platform_settings` (`ai.tiers`, `ai.settings`).

Fonctions (`JibiatAi::FEATURES`) : déjà en service, la lecture des messages WhatsApp en courses ; les autres sont
affichées « bientôt » sur la page et arrivent sans changer le prix.

## Entreprise : page Jibiat IA (`/admin/jibiat-ia`, droit `settings.manage`)

- **Essai gratuit** une seule fois (`ai_trial_started_at`), au niveau choisi ; arrêtable tout de suite.
- **Abonnement** au mois ou à l'année. Au mois : facturé au prorata des jours sur la facture de la plateforme.
  À l'année : le prix de 10 mois sur la facture du mois anniversaire ; changer de niveau passe par l'équipe Jibiat.
- **Arrêt** : en fin de mois (ou de l'année payée), annulable d'ici là ; la commande `ai:review` passe ensuite
  l'abonnement à « arrêté ».
- **Consommation du mois** : courses prises en charge (comme la facture), notes vocales, appels par fonction.
- **Recharges** (5 000, 10 000, 20 000 F) : plus de courses comprises (montant ÷ prix d'une course en plus) et plafond
  de coût relevé dans la même proportion que le niveau ; facturées sur la facture suivante.

## Coût réel et plafond

Chaque appel à Claude est compté dans `ai_usages` (mois, fonction, jetons, coût en millièmes de franc) par
`JibiatAi::record()`, aux prix par million de jetons (Haiku 0,10 / 0,50 $, Sonnet 2 / 10 $, Opus 4 / 20 $). La lecture
des messages WhatsApp (`ClaudeOrderParser`) est comptée pour toutes les entreprises. Pour une entreprise abonnée,
quand le coût du mois atteint le plafond, l'IA se met en pause jusqu'au mois suivant (`ai_paused_month`) ou à une
recharge, et Jibiat continue avec ses règles habituelles. `JibiatAi::allows($company, $feature)` dit si une fonction
peut servir (abonnement, niveau, pas de pause) : les nouvelles fonctions IA passent par lui.

## Facture de la plateforme

`Billing` ajoute `ai_amount` et `ai_lines` (abonnement, courses au-delà du volume sur la facture du mois entier,
recharges) ; les recharges facturées gardent le numéro de la facture.

## Alertes et revue quotidienne (`ai:review`, 08 h 10)

Fin d'essai dans 3 jours, essai terminé, volume du mois à 80 % puis 100 %, pause au plafond : cloche des
administrateurs (`JibiatAiNotice`), une fois par mois et par étape.

## Console (`/console/jibiat-ia`)

Entreprises (niveau, courses, payé estimé du mois, coût réel, marge, proposition du niveau au-dessus à 80 % du
volume), puis niveaux et réglages (Propriétaire).

Code : `app/Services/Ai/JibiatAi.php`, `JibiatAiController`, `ConsoleJibiatAiController`, tests `JibiatAiTest`.
