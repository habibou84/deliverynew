<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppAccount;
use App\Services\Messaging\Gateways\MetaCloudGateway;
use Throwable;

/**
 * Photo reçue sur WhatsApp, pour la lecture d'une commande par l'IA.
 *
 * Numéro relié à Meta : le webhook donne l'identifiant du fichier, téléchargé chez Meta. Numéro relié par QR code :
 * le service whatsapp-bridge (même serveur) a déjà enregistré la photo dans storage/app/whatsapp-bridge/media ; elle
 * est lue puis effacée. Photos de 5 Mo au plus, en JPEG, PNG, WebP ou GIF.
 */
class WhatsAppMedia
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    public const TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public function __construct(private readonly MetaCloudGateway $meta) {}

    /**
     * @param  array{id?: string, file?: string}  $media
     * @return array{0: string, 1: string}|null contenu et type, ou null si la photo n'est pas lisible
     */
    public function photo(array $media, ?int $accountId): ?array
    {
        try {
            [$bytes, $mime] = match (true) {
                isset($media['file']) => $this->fromBridge((string) $media['file']),
                isset($media['id']) => $this->meta->downloadMedia(WhatsAppAccount::withoutGlobalScopes()->find($accountId), (string) $media['id']),
                default => [null, null],
            };
        } catch (Throwable $e) {
            report($e);

            return null;
        }
        $mime = strtolower(trim(explode(';', (string) $mime)[0]));

        return is_string($bytes) && $bytes !== '' && strlen($bytes) <= self::MAX_BYTES && in_array($mime, self::TYPES, true) ? [$bytes, $mime] : null;
    }

    /** Dossier commun avec le service whatsapp-bridge */
    public static function bridgeDir(): string
    {
        return storage_path('app/whatsapp-bridge/media');
    }

    /** @return array{0: ?string, 1: ?string} */
    private function fromBridge(string $file): array
    {
        // Seulement un nom de fichier donné par le service (jamais un chemin)
        if (! preg_match('/^[A-Za-z0-9_-]{1,100}\.(jpe?g|png|webp|gif)$/', $file)) {
            return [null, null];
        }
        $path = self::bridgeDir().'/'.$file;
        if (! is_file($path)) {
            return [null, null];
        }
        $bytes = file_get_contents($path);
        @unlink($path);
        $mime = match (pathinfo($file, PATHINFO_EXTENSION)) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => 'image/jpeg',
        };

        return [$bytes === false ? null : $bytes, $mime];
    }
}
