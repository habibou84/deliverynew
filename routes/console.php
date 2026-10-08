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
Schedule::command('storage:bill')->monthlyOn(1, '01:10')->withoutOverlapping();
Schedule::command('model:prune', ['--model' => [IdempotencyKey::class, WebhookDelivery::class, CourierLocation::class]])->daily();
