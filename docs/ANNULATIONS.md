# Annulations : garde-fous

Une course n'est jamais effacée : elle est **annulée** (ou **refusée** à la création), et seulement **avant le
ramassage**. Le journal de la course et les écritures d'argent ne se modifient pas.

## Motif obligatoire
- Équipe (fiche course, « Annuler la course », « Refuser », « Changer le statut… ») : motif de
  `Contract::CANCEL_REASONS_COMPANY` (demande de l'e-commerçant, client injoignable, hors zone, course en double,
  autre) et précision ; « Autre » demande une précision.
- E-commerçant (application, Jibiat Marchand, API publique) : motif de `Contract::CANCEL_REASONS_MERCHANT`
  (`reason_code`) ; un texte seul (`reason`) vaut « Autre ».
- La course garde `cancel_reason_code`, `cancel_reason` (précision), `cancelled_by_type` (merchant / company) et
  `cancelled_by_id`. L'e-commerçant est prévenu avec le motif et le nom de la personne (« par Koné (Dispatch) ») ;
  Jibiat Marchand reçoit `cancellation` (contrat 1.9).

## Limite du jour et validation
- Administrateurs : sans limite. Autres membres de l'équipe (dispatch) : `staff_daily_limit` annulations ou refus
  par jour (5 par défaut). Au-delà, l'annulation devient une **demande** : la course reste active, les
  administrateurs sont prévenus et valident ou refusent dans Courses › Annulations. Validée, l'annulation est faite au
  nom de la personne qui l'a demandée (« validée par … » dans le journal) ; si la course a été ramassée entre-temps,
  la demande est sans objet.

## Point et alertes (Courses › Annulations)
- Période, demandes à valider, totaux (par les e-commerçants, par l'équipe, refus), tableaux par e-commerçant (taux
  sur les courses créées) et par membre de l'équipe, motifs.
- Règles (administrateurs) : limite du jour ; alerte si une même personne fait plus de `hourly_alert` annulations
  en une heure (une alerte par heure) ; alerte chaque matin pour les e-commerçants au-dessus de
  `merchant_rate_alert` % sur 7 jours (à partir de 10 courses) ; ligne dans le point de la semaine (cloche).

Code : `App\Services\Orders\Cancellations`, `CancellationController`, `CancellationRequest` ;
écrans `CancelReasonPicker.vue`, `views/admin/Cancellations.vue` ; tests `CancellationGuardsTest`.
