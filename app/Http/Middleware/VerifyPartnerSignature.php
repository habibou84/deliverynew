<?php

namespace App\Http\Middleware;

use App\Services\Connect\DirectConnect;
use Closure;
use Illuminate\Http\Request;
use Jibiat\Socle\Contract\Contract;
use Jibiat\Socle\Webhooks\WebhookSignature;
use Symfony\Component\HttpFoundation\Response;

/**
 * Appel signé de Jibiat Marchand (contrat socle 1.12, comme les cartes Premium) : X-Jibiat-Timestamp et
 * X-Jibiat-Signature sur le corps brut (POST) ou le chemin avec sa requête (GET, DELETE), 5 minutes au plus.
 */
class VerifyPartnerSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = DirectConnect::secret();
        if ($secret === '') {
            return response()->json(['error' => 'unavailable', 'message' => 'Connexion directe non configurée.'], 503);
        }

        $signed = $request->isMethod('POST') ? $request->getContent() : $request->getRequestUri();
        $valid = WebhookSignature::verify(
            $secret,
            $request->header(Contract::PARTNER_TIMESTAMP_HEADER),
            $signed,
            $request->header(Contract::PARTNER_SIGNATURE_HEADER),
        );

        if (! $valid) {
            return response()->json(['error' => 'invalid_signature', 'message' => 'Signature invalide ou expirée.'], 401);
        }

        return $next($request);
    }
}
