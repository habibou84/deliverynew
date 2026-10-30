<?php

use App\Jobs\RunLegacyOrdersImport;
use App\Jobs\RunLegacyPurge;
use App\Jobs\RunLegacyRecheck;
use App\Models\CourierLocation;
use App\Models\IdempotencyKey;
use App\Models\WebhookDelivery;
use App\Services\Platform\PlatformHealth;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('sanctum:prune-expired --hours=24')->daily();
// Battement des tâches planifiées (Console › Santé) et alertes de panne aux super administrateurs
Schedule::call(fn () => Cache::put(PlatformHealth::HEARTBEAT, now()->toIso8601String(), now()->addDay()))->everyMinute()->name('platform:heartbeat');
Schedule::command('platform:health --alert')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('horizon:snapshot')->everyFiveMinutes();
Schedule::command('reports:send')->everyFiveMinutes()->withoutOverlapping();
// Point de la semaine des administrateurs : vérifié toutes les 15 minutes, envoyé le lundi à 7 h 30 (heure de l'entreprise)
Schedule::command('reports:weekly')->everyFifteenMinutes()->withoutOverlapping();
// Domaines personnalisés en attente : activés dès qu'ils répondent en HTTPS
Schedule::command('domains:check')->everyTenMinutes()->withoutOverlapping();
// Fiches de paie des livreurs à la fin de chaque période (prêtes à payer le matin)
Schedule::command('payroll:close')->dailyAt('00:20')->withoutOverlapping();
// Position des marchands d'après les ramassages des livreurs (rattrapage)
// Ancienne application : commandes créées ou modifiées là-bas copiées chaque minute ; courses en cours relues la nuit
Schedule::command('legacy:sync')->everyMinute()->withoutOverlapping(10);
Schedule::command('legacy:sync --recheck')->dailyAt('02:40')->withoutOverlapping();
// Reprise des commandes coupée (redémarrage, mise à jour) : relancée depuis son point d'arrêt
Schedule::call(fn () => RunLegacyOrdersImport::resumeStalled())->everyMinute()->name('legacy:resume-import')->withoutOverlapping();
Schedule::call(fn () => RunLegacyPurge::resumeStalled())->everyMinute()->name('legacy:resume-purge')->withoutOverlapping();
Schedule::call(fn () => RunLegacyRecheck::resumeStalled())->everyMinute()->name('legacy:resume-recheck')->withoutOverlapping();
Schedule::command('merchants:locate')->dailyAt('03:10')->withoutOverlapping();
Schedule::command('storage:bill')->monthlyOn(1, '01:10')->withoutOverlapping();
// Abonnements des entreprises : factures du mois écoulé, puis rappels d'impayés chaque matin
// Chaque nuit : le 1er, factures du mois écoulé ; le lundi, factures de la semaine écoulée
Schedule::command('billing:invoice')->dailyAt('01:30')->withoutOverlapping();
// Livreurs actifs de chaque jour (livreurs en plus de la formule, facturés au prorata des jours)
Schedule::command('billing:couriers')->hourly()->withoutOverlapping();
Schedule::command('billing:remind')->dailyAt('08:05')->withoutOverlapping();
// Accès WhatsApp du bouton « Connecter WhatsApp » (60 jours) : relais par la plateforme ou alerte avant l'échéance
Schedule::command('whatsapp:tokens')->dailyAt('08:15')->withoutOverlapping();
// Modèles WhatsApp ajoutés depuis la connexion du numéro : soumis à Meta chaque nuit, état relu
Schedule::command('whatsapp:templates')->dailyAt('04:20')->withoutOverlapping();
Schedule::command('model:prune', ['--model' => [IdempotencyKey::class, WebhookDelivery::class, CourierLocation::class]])->daily();
// Cloche : anciennes notifications supprimées chaque nuit
Schedule::command('notifications:prune')->dailyAt('03:40')->withoutOverlapping();
Schedule::command('field-reports:remind')->everyMinute()->withoutOverlapping();
Schedule::command('parcels:overdue')->everyFifteenMinutes()->withoutOverlapping();
// Argent gardé trop longtemps par un livreur : caisse prévenue, rappel au livreur (une fois par jour)
Schedule::command('cash:held')->hourlyAt(5)->between('7:00', '21:00')->withoutOverlapping();
// Caisse pas clôturée à l'heure choisie par l'entreprise : rappel aux responsables (une fois par jour)
Schedule::command('cash:close-reminder')->everyFifteenMinutes()->withoutOverlapping();
// Courses sans livreur de ramassage ou de livraison au-delà du délai de l'entreprise
Schedule::command('orders:unassigned')->everyMinute()->withoutOverlapping();
// Livreurs libres ou bientôt libres avec des ramassages près d'eux : mission ramassage possible
Schedule::command('couriers:available')->everyMinute()->withoutOverlapping();
// Colis gardés par le livreur après une mission ramassage : leur livraison lui est confiée le jour prévu
// Planning des livreurs : rattrapage de l'assignation automatique, message du matin
Schedule::command('assignments:auto')->everyFiveMinutes()->withoutOverlapping();
// Livreur injoignable, ramassages pas commencés avant l'heure limite
Schedule::command('assignments:watch')->everyMinute()->withoutOverlapping();
Schedule::command('couriers:planning-digest')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('parcels:kept-deliveries')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('orders:due-today')->dailyAt('06:50')->withoutOverlapping();
// Papiers des véhicules et permis des livreurs : rappel 15 jours avant la fin, puis le jour même
Schedule::command('vehicles:documents')->dailyAt('07:40')->withoutOverlapping();
// Cartes Membre Premium Jibiat Marchand : état relu chez Jibiat Marchand, carte automatique aux marchands qui y ont droit
Schedule::command('premium:award')->dailyAt('10:20')->withoutOverlapping();
// Comptes supprimés depuis le profil : données personnelles effacées 30 jours après la demande
Schedule::command('accounts:erase-deleted')->dailyAt('03:40')->withoutOverlapping();
