<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use App\Notifications\PlatformHealthAlert;
use App\Services\Platform\PlatformHealth;
use App\Services\Platform\PlatformWhatsApp;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Santé de la plateforme (php artisan platform:health) ; avec --alert, prévient les super administrateurs des
 * pannes (cloche de la console et WhatsApp depuis le numéro de Jibiat), une fois toutes les 6 heures par panne.
 */
class CheckPlatformHealth extends Command
{
    protected $signature = 'platform:health {--alert : Prévenir les super administrateurs des pannes}';

    protected $description = 'Vérifie les services de la plateforme (files, tâches, WhatsApp, disque, sauvegardes…)';

    private const REPEAT_HOURS = 6;

    public function handle(PlatformHealth $health, PlatformWhatsApp $whatsapp): int
    {
        $checks = $health->checks();
        $this->table(['Point', 'État', 'Détail'], array_map(fn (array $c) => [$c['label'], $c['status'], $c['detail']], $checks));

        if (! $this->option('alert')) {
            return self::SUCCESS;
        }

        // Erreurs seulement, et une même panne pas plus d'une fois toutes les 6 heures
        $new = array_values(array_filter($checks, fn (array $c) => $c['status'] === 'error'
            && Cache::add('platform-health-alert:'.$c['key'], true, now()->addHours(self::REPEAT_HOURS))));
        if ($new === []) {
            return self::SUCCESS;
        }

        $admins = User::query()->whereNull('company_id')->where('status', 'active')->role(Role::SuperAdmin->value)->get();
        Notification::send($admins, new PlatformHealthAlert($new));

        $text = "Jibiat · ⚠️ À vérifier sur la plateforme :\n".collect($new)->map(fn (array $p) => '• '.$p['label'].' : '.$p['detail'])->implode("\n");
        foreach ($admins->pluck('phone')->filter()->unique() as $phone) {
            try {
                $whatsapp->notify($phone, $text);
            } catch (Throwable) {
                // WhatsApp lui-même en panne : la cloche suffit
            }
        }
        $this->warn(count($new).' alerte(s) envoyée(s).');

        return self::SUCCESS;
    }
}
