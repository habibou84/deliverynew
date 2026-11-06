<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\LivePayment;
use App\Services\Live\LivePayments;
use App\Services\Payments\PayDunya;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Avis de paiement de PayDunya (IPN). Authentifié par l'empreinte de la clé principale ; l'état du paiement est
 * de toute façon relu chez PayDunya avant d'être appliqué.
 */
class PayDunyaWebhookController extends Controller
{
    public function __invoke(Request $request, LivePayments $payments): JsonResponse
    {
        $data = $request->input('data', []);
        if (! is_array($data) || ! PayDunya::validHash($data['hash'] ?? null)) {
            return response()->json(['received' => false], 403);
        }

        $token = (string) ($data['invoice']['token'] ?? '');
        $payment = $token === '' ? null : LivePayment::withoutGlobalScopes()->where('token', $token)->first();
        if ($payment !== null) {
            $payments->refresh($payment);
        }

        return response()->json(['received' => true]);
    }
}
