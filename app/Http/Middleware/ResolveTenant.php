<?php

namespace App\Http\Middleware;

use App\Support\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Retrouve l'entreprise désignée par l'adresse du site (plateforme multi-entreprises).
 * Adresse d'une entreprise inconnue ou suspendue : page d'erreur. Adresse de la
 * plateforme : page de présentation, pas d'API (sauf webhooks et API publique, qui
 * identifient l'entreprise autrement).
 */
class ResolveTenant
{
    // Routes indépendantes de l'adresse : l'entreprise vient du message ou de la clé d'API
    private const ANY_HOST = ['api/webhooks/*', 'api/public/*', 'up'];

    public function __construct(private Tenancy $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->tenancy->resolve($request);

        if ($this->tenancy->zone() === Tenancy::SINGLE || $request->is(...self::ANY_HOST)) {
            return $next($request);
        }

        $api = $request->is('api/*');

        if ($problem = $this->tenancy->problem()) {
            $status = $problem === 'suspended' ? 403 : 404;
            $message = $problem === 'suspended'
                ? 'Le service de cette entreprise est suspendu.'
                : 'Aucune entreprise à cette adresse.';

            return $api
                ? response()->json(['message' => $message], $status)
                : response()->view('platform.unavailable', ['message' => $message, 'domain' => Tenancy::domain()], $status);
        }

        if ($this->tenancy->zone() === Tenancy::PLATFORM) {
            return $api
                ? response()->json(['message' => 'Ouvrez l\'adresse de votre entreprise de livraison.'], 404)
                : response()->view('platform.home', ['domain' => Tenancy::domain()]);
        }

        return $next($request);
    }
}
