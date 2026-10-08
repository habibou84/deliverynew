<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Messaging\Gateways\LogSmsGateway;
use App\Services\Messaging\Gateways\LogWhatsAppGateway;
use App\Services\Messaging\Gateways\MetaCloudGateway;
use App\Services\Messaging\Gateways\SmsGateway;
use App\Services\Messaging\Gateways\TwilioSmsGateway;
use App\Services\Messaging\Gateways\WhatsAppGateway;
use App\Services\WhatsApp\ClaudeOrderParser;
use App\Services\WhatsApp\HeuristicOrderParser;
use App\Services\WhatsApp\OrderMessageParser;
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

        // Messages WhatsApp libres : Claude si une clé API est configurée, sinon analyse par règles
        $this->app->bind(OrderMessageParser::class, fn ($app) => filled(config('messaging.ai.api_key'))
            ? $app->make(ClaudeOrderParser::class)
            : $app->make(HeuristicOrderParser::class));

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

        // API publique : quota par clé, identifiée par son préfixe (en-têtes X-RateLimit-* renvoyés)
        RateLimiter::for('public-api', function (Request $request) {
            $key = (string) ($request->bearerToken() ?? $request->header('X-Api-Key'));

            return Limit::perMinute((int) config('services.public_api.rate_limit', 120))
                ->by(preg_match('/^lv_([a-z0-9]{8})_/', $key, $m) ? 'api-key:'.$m[1] : 'api-ip:'.$request->ip());
        });
    }
}
