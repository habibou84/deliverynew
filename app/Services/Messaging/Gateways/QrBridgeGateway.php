<?php

namespace App\Services\Messaging\Gateways;

use App\Enums\WhatsAppTemplate;
use App\Models\WhatsAppAccount;
use App\Services\Messaging\MessagingException;
use App\Services\Messaging\WhatsAppBridge;
use Illuminate\Support\Facades\Cache;

/**
 * Envoi par la connexion QR code (service whatsapp-bridge). Pas de modèles Meta : le texte du modèle est envoyé tel
 * quel. Les boutons deviennent une liste numérotée ; la réponse « 1 », « 2 »… (ou le libellé) est reconnue comme le
 * bouton à la réception (voir buttonFor).
 */
class QrBridgeGateway implements WhatsAppGateway
{
    private const BUTTONS_TTL = 86400;

    public function __construct(private readonly WhatsAppBridge $bridge) {}

    public function sendTemplate(?WhatsAppAccount $account, string $to, string $template, string $language, array $params): string
    {
        $text = WhatsAppTemplate::tryFrom($template)?->render($params);
        if ($text === null) {
            throw new MessagingException("Modèle inconnu : {$template}.");
        }

        return $this->bridge->send($this->account($account), $to, $text);
    }

    public function sendReply(?WhatsAppAccount $account, string $to, string $text, array $buttons = []): string
    {
        $account = $this->account($account);
        if ($buttons !== []) {
            $lines = [];
            $choices = [];
            $number = 1;
            foreach (array_slice($buttons, 0, 10, true) as $id => $label) {
                $lines[] = "{$number}. {$label}";
                $choices[$number++] = ['id' => (string) $id, 'label' => $label];
            }
            $text .= "\n\n".implode("\n", $lines)."\n\nRépondez par le numéro de votre choix.";
            Cache::put(self::buttonsKey($account, $to), $choices, self::BUTTONS_TTL);
        }

        return $this->bridge->send($account, $to, $text);
    }

    public function templates(?WhatsAppAccount $account): array
    {
        // Les messages partent en texte libre : pas de modèles à faire approuver
        return [];
    }

    /**
     * Bouton choisi par une réponse « 2 » ou par le libellé, d'après les derniers boutons envoyés à ce numéro.
     *
     * @return array{id: string, label: string}|null
     */
    public static function buttonFor(WhatsAppAccount $account, string $from, ?string $text): ?array
    {
        $buttons = Cache::get(self::buttonsKey($account, $from));
        $answer = mb_strtolower(trim((string) $text));
        if (! is_array($buttons) || $answer === '') {
            return null;
        }
        if (preg_match('/^(\d{1,2})[.)]?$/', $answer, $m) && isset($buttons[(int) $m[1]])) {
            return $buttons[(int) $m[1]];
        }
        foreach ($buttons as $button) {
            if (mb_strtolower($button['label']) === $answer) {
                return $button;
            }
        }

        return null;
    }

    private static function buttonsKey(WhatsAppAccount $account, string $to): string
    {
        return 'wa-qr-buttons:'.$account->id.':'.preg_replace('/\D/', '', $to);
    }

    private function account(?WhatsAppAccount $account): WhatsAppAccount
    {
        if ($account === null || ! $account->isQr()) {
            throw new MessagingException('Aucun numéro WhatsApp relié par QR code.');
        }

        return $account;
    }
}
