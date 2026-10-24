<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Papier avec sa date de fin : ceux du véhicule (carte grise, assurance, visite technique, vignette) ou
 * le permis du livreur. Les responsables sont prévenus 15 jours avant la fin, puis le jour même.
 */
class VehicleDocument extends Model
{
    use BelongsToCompany;

    /** Papiers du véhicule */
    public const VEHICLE_TYPES = [
        'registration' => 'Carte grise',
        'insurance' => 'Assurance',
        'inspection' => 'Visite technique',
        'tax' => 'Vignette',
        'other' => 'Autre papier',
    ];

    /** Papiers du livreur */
    public const COURIER_TYPES = ['license' => 'Permis de conduire'];

    /** Prévenir tant de jours avant la fin */
    public const WARN_DAYS = 15;

    protected $fillable = [
        'company_id', 'vehicle_id', 'courier_id', 'type', 'number', 'expires_at',
        'file_path', 'file_mime', 'notes', 'warned_at', 'expired_warned_at',
    ];

    protected function casts(): array
    {
        return ['expires_at' => 'date', 'warned_at' => 'datetime', 'expired_warned_at' => 'datetime'];
    }

    public static function labelFor(string $type): string
    {
        return self::VEHICLE_TYPES[$type] ?? self::COURIER_TYPES[$type] ?? 'Papier';
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    public function label(): string
    {
        return self::labelFor($this->type);
    }

    /** valid | soon | expired | none (sans date de fin) */
    public function state(?CarbonImmutable $today = null): string
    {
        if ($this->expires_at === null) {
            return 'none';
        }
        $today ??= CarbonImmutable::today();
        $end = CarbonImmutable::parse($this->expires_at)->startOfDay();

        return match (true) {
            $end->lt($today) => 'expired',
            $end->lte($today->addDays(self::WARN_DAYS)) => 'soon',
            default => 'valid',
        };
    }

    /** Jours restants (négatif : expiré depuis) */
    public function daysLeft(?CarbonImmutable $today = null): ?int
    {
        return $this->expires_at === null ? null
            : (int) ($today ?? CarbonImmutable::today())->diffInDays(CarbonImmutable::parse($this->expires_at)->startOfDay(), false);
    }
}
