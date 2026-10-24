<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Message d'une conversation de la console : reçu (« in ») ou envoyé depuis la console (« out »).
 */
class PlatformMessage extends Model
{
    public const IN = 'in';

    public const OUT = 'out';

    /** Ordre des statuts d'un message envoyé : un accusé en retard ne fait pas reculer le statut */
    private const RANK = ['queued' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3, 'failed' => 4];

    protected $fillable = ['platform_conversation_id', 'direction', 'body', 'status', 'provider_message_id', 'error', 'user_id'];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(PlatformConversation::class, 'platform_conversation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function advanceTo(string $status, ?string $error = null): void
    {
        if ($this->status === 'failed' || (self::RANK[$status] ?? -1) <= (self::RANK[$this->status] ?? -1)) {
            return;
        }
        $this->forceFill(['status' => $status, 'error' => $error ?? $this->error])->save();
    }
}
