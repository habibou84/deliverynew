<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Messaging\Gateways\LogSmsGateway;
use App\Services\Messaging\Gateways\LogWhatsAppGateway;
use App\Services\Messaging\Gateways\MetaCloudGateway;
use App\Services\Messaging\Gateways\SmsGateway;
use App\Services\Messaging\Gateways\TwilioSmsGateway;
use App\Services\Messaging\Gateways\WhatsAppGateway;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(WhatsAppGateway::class, fn () => match (config('messaging.whatsapp.driver')) {
            'meta' => new MetaCloudGateway,
            default => new LogWhatsAppGateway,
        });

        $this->app->bind(SmsGateway::class, fn () => match (config('messaging.sms.driver')) {
            'twilio' => new TwilioSmsGateway,
            default => new LogSmsGateway,
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Détecte en développement les requêtes N+1 et les attributs non assignables ignorés
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        DB::prohibitDestructiveCommands($this->app->isProduction());

        // Le super administrateur de la plateforme a tous les droits
        Gate::before(fn (User $user) => $user->isSuperAdmin() ? true : null);

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(mb_strtolower((string) $request->input('login')).'|'.$request->ip()),
            Limit::perMinute(20)->by($request->ip()),
        ]);
    }
}
