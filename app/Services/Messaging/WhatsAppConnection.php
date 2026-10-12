<?php

namespace App\Services\Messaging;

use App\Enums\WhatsAppTemplate;
use App\Models\WhatsAppAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Branchement d'un numéro WhatsApp Business (API Cloud de Meta) :
 *  - vérifie le numéro et le jeton (nom affiché, note de qualité) ;
 *  - abonne l'application Meta de la plateforme au compte WhatsApp, pour recevoir
 *    les messages et les accusés de réception ;
 *  - soumet les modèles de messages à Meta et relève leur état.
 * Une connexion réussie fait passer l'entreprise en envois réels (WHATSAPP_DRIVER=auto).
 */
class WhatsAppConnection
{
    // Modèle déjà existant chez Meta (même nom et même langue) : rien à faire
    private const TEMPLATE_EXISTS_SUBCODES = [2388023, 2388024];

    /**
     * Vérifie le numéro auprès de Meta et met à jour son état ; renvoie le compte.
     */
    public function connect(WhatsAppAccount $account): WhatsAppAccount
    {
        if (! $account->isConfigured()) {
            $account->forceFill(['status' => 'pending', 'last_error' => null])->save();

            return $account;
        }

        try {
            $info = $this->call(fn () => $this->client($account)->get($account->phone_number_id, [
                'fields' => 'display_phone_number,verified_name,quality_rating',
            ]));
        } catch (MessagingException $e) {
            $account->forceFill(['status' => 'error', 'last_error' => $this->explain($e)])->save();

            return $account;
        }

        $account->forceFill([
            'status' => 'connected',
            'last_error' => null,
            'display_phone' => $info->json('display_phone_number') ? preg_replace('/[^\d+]/', '', $info->json('display_phone_number')) : $account->display_phone,
            'verified_name' => $info->json('verified_name'),
            'quality_rating' => $info->json('quality_rating') ? strtolower($info->json('quality_rating')) : null,
            'connected_at' => $account->connected_at ?? now(),
        ])->save();

        $this->subscribe($account);

        return $account;
    }

    /**
     * Abonne l'application Meta au compte WhatsApp Business (messages reçus, accusés).
     */
    public function subscribe(WhatsAppAccount $account): bool
    {
        if (blank($account->waba_id)) {
            return false;
        }

        try {
            $this->call(fn () => $this->client($account)->post("{$account->waba_id}/subscribed_apps"));
        } catch (MessagingException $e) {
            $account->forceFill(['last_error' => 'Numéro connecté, mais abonnement aux messages reçus impossible : '.$this->explain($e)])->save();

            return false;
        }

        $account->forceFill(['webhook_subscribed_at' => now()])->save();

        return true;
    }

    /**
     * Soumet à Meta les modèles du catalogue qui ne sont ni approuvés ni en cours de
     * validation. Renvoie, par modèle soumis, « ok » ou le message d'erreur.
     *
     * @return array<string, string>
     */
    public function submitTemplates(WhatsAppAccount $account): array
    {
        if (! $account->isConfigured() || blank($account->waba_id)) {
            throw new MessagingException('Renseignez et connectez d\'abord le numéro, avec l\'identifiant du compte WhatsApp Business (WABA ID).');
        }

        $known = $account->templates()->where('language', WhatsAppTemplate::LANGUAGE)->pluck('status', 'name');
        $results = [];

        foreach (WhatsAppTemplate::cases() as $template) {
            if (in_array($known[$template->value] ?? null, ['approved', 'pending'], true)) {
                continue;
            }

            try {
                $response = $this->call(fn () => $this->client($account)->post("{$account->waba_id}/message_templates", [
                    'name' => $template->value,
                    'language' => WhatsAppTemplate::LANGUAGE,
                    'category' => 'UTILITY',
                    'components' => [[
                        'type' => 'BODY',
                        'text' => $template->body(),
                        'example' => ['body_text' => [$template->example()]],
                    ]],
                ]), allowExisting: true);

                $status = $response ? strtolower($response->json('status') ?? 'pending') : 'pending';
                $account->templates()->updateOrCreate(
                    ['name' => $template->value, 'language' => WhatsAppTemplate::LANGUAGE],
                    ['status' => $status, 'category' => 'utility', 'rejected_reason' => null, 'synced_at' => now()],
                );
                $results[$template->value] = 'ok';
            } catch (MessagingException $e) {
                $results[$template->value] = $this->explain($e);
            }
        }

        return $results;
    }

    /**
     * Message compréhensible pour les erreurs Meta les plus courantes.
     */
    private function explain(MessagingException $e): string
    {
        return match ($e->providerCode) {
            '190' => 'Jeton d\'accès invalide ou expiré. Créez un jeton permanent (utilisateur système) dans Meta Business.',
            '100' => 'Identifiant introuvable ou inaccessible avec ce jeton. Vérifiez l\'identifiant du numéro et du compte WhatsApp Business.',
            '10', '200' => 'Ce jeton n\'a pas les autorisations WhatsApp nécessaires (whatsapp_business_management, whatsapp_business_messaging).',
            default => $e->getMessage(),
        };
    }

    private function client(WhatsAppAccount $account): PendingRequest
    {
        $base = rtrim(config('messaging.whatsapp.graph_url'), '/').'/'.config('messaging.whatsapp.graph_version');

        return Http::baseUrl($base)->withToken($account->access_token)->acceptJson()->timeout(15);
    }

    /**
     * @param  callable(): Response  $request
     */
    private function call(callable $request, bool $allowExisting = false): ?Response
    {
        try {
            $response = $request();
        } catch (ConnectionException $e) {
            throw new MessagingException('Meta injoignable : '.$e->getMessage(), retryable: true);
        } catch (RequestException $e) {
            // Réponse d'erreur levée en exception (proxy, client configuré pour lever) : traitée comme les autres
            $response = $e->response;
        }

        if ($response->successful()) {
            return $response;
        }

        if ($allowExisting && in_array($response->json('error.error_subcode'), self::TEMPLATE_EXISTS_SUBCODES, true)) {
            return null;
        }

        $code = $response->json('error.code');

        throw new MessagingException(
            'Meta : '.($response->json('error.error_user_msg') ?? $response->json('error.message') ?? 'erreur HTTP '.$response->status()),
            providerCode: $code !== null ? (string) $code : null,
        );
    }
}
