<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ligne de l'historique d'un compte (qui a fait quoi, quand). Écrite seulement, jamais modifiée.
 */
class UserAccountEvent extends Model
{
    public const UPDATED_AT = null;

    public const CREATED = 'created';

    public const ROLE_CHANGED = 'role_changed';

    public const SUSPENDED = 'suspended';

    public const REACTIVATED = 'reactivated';

    public const RENAMED = 'renamed';

    public const CREDENTIALS_CHANGED = 'credentials_changed';

    public const INVITATION_SENT = 'invitation_sent';

    public const LOGGED_OUT = 'logged_out';

    protected $fillable = ['company_id', 'user_id', 'actor_id', 'action', 'data'];

    protected function casts(): array
    {
        return ['data' => 'array', 'created_at' => 'datetime'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id')->withoutGlobalScopes();
    }

    public static function record(User $user, ?User $actor, string $action, array $data = []): self
    {
        return self::create([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'actor_id' => $actor?->id,
            'action' => $action,
            'data' => $data ?: null,
        ]);
    }

    /** Phrase lisible : « Rôle changé : Caissier → Dispatcher » */
    public function text(): string
    {
        $d = $this->data ?? [];

        return match ($this->action) {
            self::CREATED => 'Compte créé'.(isset($d['role']) ? ' ('.(Role::tryFrom($d['role'])?->label() ?? $d['role']).')' : ''),
            self::ROLE_CHANGED => 'Rôle changé : '.(Role::tryFrom($d['from'] ?? '')?->label() ?? '—').' → '.(Role::tryFrom($d['to'] ?? '')?->label() ?? '—'),
            self::SUSPENDED => 'Compte suspendu',
            self::REACTIVATED => 'Compte réactivé',
            self::RENAMED => 'Nom changé : '.($d['from'] ?? '—').' → '.($d['to'] ?? '—'),
            self::CREDENTIALS_CHANGED => 'Accès modifiés : '.implode(', ', array_map(fn ($f) => ['phone' => 'téléphone', 'email' => 'e-mail', 'password' => 'mot de passe'][$f] ?? $f, $d['fields'] ?? [])),
            self::INVITATION_SENT => 'Lien de connexion préparé',
            self::LOGGED_OUT => 'Déconnecté de tous ses appareils',
            default => $this->action,
        };
    }
}
