<?php

namespace App\Services\Payments;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * PayDunya (paiement par Orange Money, MTN, Moov, Wave ou carte) : facture de paiement (« checkout invoice ») sur la
 * page de PayDunya, puis confirmation auprès de PayDunya. Un paiement n'est jamais tenu pour fait sur la seule foi
 * de l'avis reçu : son état est toujours relu par l'API.
 */
class PayDunya
{
    public static function configured(): bool
    {
        $c = config('payments.paydunya');

        return filled($c['master_key']) && filled($c['private_key']) && filled($c['token']);
    }

    /**
     * Facture de paiement : renvoie le jeton PayDunya et l'adresse de la page de paiement.
     *
     * @param  array<string, mixed>  $custom
     * @return array{token: string, url: string}
     *
     * @throws PaymentException
     */
    public function createInvoice(int $amount, string $description, string $returnUrl, string $cancelUrl, string $callbackUrl, array $custom = []): array
    {
        $response = $this->send(fn (PendingRequest $http) => $http->post('checkout-invoice/create', [
            'invoice' => ['total_amount' => $amount, 'description' => mb_substr($description, 0, 250)],
            'store' => ['name' => config('payments.paydunya.store_name')],
            'actions' => ['return_url' => $returnUrl, 'cancel_url' => $cancelUrl, 'callback_url' => $callbackUrl],
            'custom_data' => $custom,
        ]));

        if (($response['response_code'] ?? null) !== '00' || blank($response['token'] ?? null)) {
            throw new PaymentException('PayDunya a refusé le paiement : '.($response['response_text'] ?? 'réponse inattendue').'.');
        }

        return ['token' => (string) $response['token'], 'url' => (string) $response['response_text']];
    }

    /**
     * État d'un paiement relu chez PayDunya : completed, pending, cancelled ou failed, et le montant payé.
     *
     * @return array{status: string, amount: int, method: ?string}
     *
     * @throws PaymentException
     */
    public function confirm(string $token): array
    {
        $response = $this->send(fn (PendingRequest $http) => $http->get('checkout-invoice/confirm/'.rawurlencode($token)));
        if (($response['response_code'] ?? null) !== '00') {
            throw new PaymentException('Paiement introuvable chez PayDunya.');
        }
        $status = strtolower((string) ($response['status'] ?? 'pending'));

        return [
            'status' => in_array($status, ['completed', 'pending', 'cancelled', 'failed'], true) ? $status : 'pending',
            'amount' => (int) ($response['invoice']['total_amount'] ?? 0),
            'method' => isset($response['customer']['payment_method']) ? (string) $response['customer']['payment_method'] : null,
        ];
    }

    /** Avis de PayDunya authentique : empreinte SHA-512 de la clé principale */
    public static function validHash(?string $hash): bool
    {
        $key = (string) config('payments.paydunya.master_key');

        return $key !== '' && is_string($hash) && hash_equals(hash('sha512', $key), $hash);
    }

    /**
     * @param  callable(PendingRequest): Response  $request
     * @return array<string, mixed>
     *
     * @throws PaymentException
     */
    private function send(callable $request): array
    {
        if (! self::configured()) {
            throw new PaymentException('Le paiement en ligne n\'est pas encore configuré.');
        }
        $c = config('payments.paydunya');
        $base = rtrim($c['url'], '/').($c['mode'] === 'live' ? '/api/v1/' : '/sandbox-api/v1/');

        try {
            $response = $request(Http::baseUrl($base)->acceptJson()->asJson()->timeout(20)->withHeaders([
                'PAYDUNYA-MASTER-KEY' => $c['master_key'],
                'PAYDUNYA-PRIVATE-KEY' => $c['private_key'],
                'PAYDUNYA-TOKEN' => $c['token'],
            ]));
        } catch (ConnectionException) {
            throw new PaymentException('PayDunya ne répond pas. Réessayez dans un instant.');
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new PaymentException('Réponse illisible de PayDunya. Réessayez dans un instant.');
        }

        return $json;
    }
}
