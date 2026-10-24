<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\MessageStatus;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessInboundWhatsApp;
use App\Models\InboundMessage;
use App\Models\OutboundMessage;
use App\Models\WhatsAppAccount;
use App\Services\Messaging\Gateways\QrBridgeGateway;
use App\Services\Messaging\Messenger;
use App\Services\Platform\PlatformWhatsApp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Événements du service whatsapp-bridge (connexion par QR code), signés avec WHATSAPP_BRIDGE_TOKEN : état de la
 * session (QR scanné, téléphone déconnecté), messages reçus, messages envoyés, remis, lus ou en échec. La session
 * « platform » est le numéro de Jibiat (messagerie de la console).
 */
class WhatsAppBridgeWebhookController extends Controller
{
    public function __construct(
        private readonly Messenger $messenger,
        private readonly PlatformWhatsApp $platform,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $token = (string) config('messaging.whatsapp.bridge.token');
        $expected = hash_hmac('sha256', $request->getContent(), $token);
        abort_unless($token !== '' && hash_equals($expected, (string) $request->header('X-Bridge-Signature')), 403, 'Signature invalide.');

        // Numéro de Jibiat : messagerie de la console
        if ($request->input('session') === PlatformWhatsApp::SESSION) {
            $this->platform->handle($request->all());

            return response()->json(['received' => true]);
        }

        $account = $this->account((string) $request->input('session'));
        if ($account === null) {
            return response()->json(['received' => false]);
        }

        match ((string) $request->input('type')) {
            'status' => $this->status($account, $request),
            'message' => $this->message($account, $request),
            'sent' => $this->advance((string) $request->input('id'), MessageStatus::Sent),
            'delivered' => $this->advance((string) $request->input('id'), MessageStatus::Delivered),
            'read' => $this->advance((string) $request->input('id'), MessageStatus::Read),
            'failed' => $this->advance((string) $request->input('id'), MessageStatus::Failed, (string) $request->input('error')),
            default => null,
        };

        return response()->json(['received' => true]);
    }

    /** Session « company-12 » : le compte WhatsApp relié par QR code de l'entreprise 12 */
    private function account(string $session): ?WhatsAppAccount
    {
        if (! preg_match('/^company-(\d+)$/', $session, $m)) {
            return null;
        }

        return WhatsAppAccount::withoutGlobalScopes()->where('company_id', (int) $m[1])->where('owner_type', 'company')
            ->where('provider', WhatsAppAccount::QR)->first();
    }

    private function status(WhatsAppAccount $account, Request $request): void
    {
        $status = (string) $request->input('status');
        match ($status) {
            'connected' => $account->forceFill([
                'status' => 'connected',
                'display_phone' => $request->input('phone') ?: $account->display_phone,
                'verified_name' => $request->input('name') ?: $account->verified_name,
                'connected_at' => now(),
                'connected_via' => 'qr',
                'last_error' => null,
            ])->save(),
            'logged_out' => $account->forceFill(['status' => 'disconnected', 'last_error' => $request->input('error') ?: 'Téléphone déconnecté : scannez un nouveau QR code.'])->save(),
            'disconnected' => $account->forceFill(['last_error' => $request->input('error')])->save(),
            default => null,
        };
    }

    /** Message reçu : confié à la conversation (création de course…), une seule fois */
    private function message(WhatsAppAccount $account, Request $request): void
    {
        $id = (string) $request->input('id');
        $from = '+'.ltrim((string) $request->input('from'), '+');
        if ($id === '' || $from === '+' || InboundMessage::withoutGlobalScopes()->where('provider_message_id', $id)->exists()) {
            return;
        }

        $text = $request->input('text');
        $kind = (string) $request->input('kind', 'text');
        // Réponse « 1 », « 2 »… à une liste de choix : le bouton correspondant
        $button = $kind === 'text' ? QrBridgeGateway::buttonFor($account, $from, $text) : null;

        ProcessInboundWhatsApp::dispatch($account->company_id, $from, $button ? 'button' : $kind, $button['label'] ?? $text, $button['id'] ?? null, [
            'whatsapp_account_id' => $account->id,
            'provider_message_id' => $id,
        ]);
    }

    private function advance(string $id, MessageStatus $status, ?string $error = null): void
    {
        $message = $id === '' ? null : OutboundMessage::withoutGlobalScopes()->where('provider_message_id', $id)->first();
        if ($message === null) {
            return;
        }
        // Passé « envoyé » à sa mise en file : un échec réel (numéro sans WhatsApp…) déclenche le SMS de repli
        if ($message->advanceTo($status, $error ? 'WhatsApp : '.$error : null) && $status === MessageStatus::Failed) {
            $this->messenger->fallbackToSms($message);
        }
    }
}
