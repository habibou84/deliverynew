<?php

namespace App\Jobs;

use App\Models\ConnectRequest;
use App\Services\Connect\DirectConnect;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Jibiat\Socle\Contract\Contract;
use Jibiat\Socle\Webhooks\WebhookSignature;
use RuntimeException;
use Throwable;

/**
 * Décision sur une connexion directe envoyée à Jibiat Marchand (connect-decision, requête signée), avec la clé
 * d'API quand le compte est ouvert. Réessayé tant que Jibiat Marchand ne répond pas 2xx ; une fois remise, la clé
 * est effacée (Jibiat Marchand peut aussi relire l'état par GET).
 */
class SendConnectDecision implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 8;

    public function __construct(public int $connectRequestId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 600, 1800, 3600, 3600, 7200];
    }

    public function handle(DirectConnect $direct): void
    {
        $request = ConnectRequest::withoutGlobalScopes()->find($this->connectRequestId);
        if ($request === null || ! $request->isDirect() || $request->decision_sent_at !== null
            || ! in_array($request->status, [ConnectRequest::ACCEPTED, ConnectRequest::DECLINED, ConnectRequest::EXPIRED], true)) {
            return;
        }

        $body = json_encode($direct->decision($request), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $timestamp = time();
        $request->forceFill(['decision_attempts' => $request->decision_attempts + 1])->save();

        try {
            $response = Http::baseUrl((string) config('services.jibiat_marchand.api_url'))
                ->acceptJson()->timeout(15)->connectTimeout(5)->withoutRedirecting()
                ->withHeaders([
                    Contract::PARTNER_TIMESTAMP_HEADER => (string) $timestamp,
                    Contract::PARTNER_SIGNATURE_HEADER => WebhookSignature::sign(DirectConnect::secret(), $timestamp, $body),
                ])
                ->withBody($body, 'application/json')
                ->post(DirectConnect::DECISION_PATH);
        } catch (Throwable $e) {
            throw new RuntimeException('Jibiat Marchand injoignable : '.$e->getMessage(), previous: $e);
        }

        if (! $response->successful()) {
            throw new RuntimeException("Jibiat Marchand a répondu {$response->status()} à la décision de connexion.");
        }

        $request->forceFill(['decision_sent_at' => now(), 'pending_key' => null])->save();
    }

    public function failed(Throwable $e): void
    {
        Log::warning('Décision de connexion non remise à Jibiat Marchand', ['connect_request_id' => $this->connectRequestId, 'error' => $e->getMessage()]);
    }
}
