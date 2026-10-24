<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Conversation WhatsApp du numéro de Jibiat (messagerie de la console) avec une entreprise ou un prospect.
 */
class PlatformConversation extends Model
{
    protected $fillable = ['phone', 'name', 'company_id', 'last_message_at', 'last_message', 'unread'];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'unread' => 'integer',
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(PlatformMessage::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
