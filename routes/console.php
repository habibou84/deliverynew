<?php

use App\Models\CourierLocation;
use App\Models\IdempotencyKey;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::command('horizon:snapshot')->everyFiveMinutes();
Schedule::command('reports:send')->everyFiveMinutes()->withoutOverlapping();
// Fiches de paie des livreurs à la fin de chaque période (prêtes à payer le matin)
Schedule::command('payroll:close')->dailyAt('00:20')->withoutOverlapping();
// Position des marchands d'après les ramassages des livreurs (rattrapage)
Schedule::command('merchants:locate')->dailyAt('03:10')->withoutOverlapping();
Schedule::command('storage:bill')->monthlyOn(1, '01:10')->withoutOverlapping();
Schedule::command('model:prune', ['--model' => [IdempotencyKey::class, WebhookDelivery::class, CourierLocation::class]])->daily();
Schedule::command('field-reports:remind')->everyMinute()->withoutOverlapping();
Schedule::command('parcels:overdue')->everyFifteenMinutes()->withoutOverlapping();
// Courses sans livreur de ramassage ou de livraison au-delà du délai de l'entreprise
Schedule::command('orders:unassigned')->everyMinute()->withoutOverlapping();
Schedule::command('orders:due-today')->dailyAt('06:50')->withoutOverlapping();
