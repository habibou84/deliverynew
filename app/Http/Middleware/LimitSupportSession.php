<?php

namespace App\Http\Middleware;

use App\Models\SupportSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Session d'assistance (jeton « support:… ») : l'équipe Jibiat aide dans l'entreprise mais ne peut rien créer qui
 * lui garderait un accès après l'heure (clés, invitations, comptes, mots de passe, webhooks, moyens de paiement).
 */
class LimitSupportSession
{
    /** Actions refusées pendant une session d'assistance (noms de routes) */
    public const FORBIDDEN = [
        'v1.api-keys.*',
        'v1.webhooks.store', 'v1.webhooks.update', 'v1.webhooks.secret',
        'v1.company.legacy-import.key.*', 'v1.company.legacy-orders.key.*', 'v1.company.legacy-switch.key.*',
        'v1.users.store', 'v1.users.update', 'v1.users.destroy', 'v1.users.invitation',
        'v1.merchants.users.store',
        'v1.auth.password', 'v1.account.deletion',
        'v1.merchants.payment-methods.store', 'v1.merchant-payment-methods.*',
        'v1.connect.authorize',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $name = (string) $request->route()?->getName();
        if ($name !== '' && Str::is(self::FORBIDDEN, $name) && $this->isSupport($request)) {
            return response()->json(['message' => 'Action impossible pendant une session d\'assistance : demandez-la à un administrateur de l\'entreprise.'], 403);
        }

        return $next($request);
    }

    private function isSupport(Request $request): bool
    {
        $bearer = $request->bearerToken();
        if (blank($bearer)) {
            return false;
        }
        $token = PersonalAccessToken::findToken($bearer);

        return $token !== null && str_starts_with((string) $token->name, SupportSession::TOKEN_PREFIX);
    }
}
