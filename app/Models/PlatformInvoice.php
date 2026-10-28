<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Facture mensuelle d'une entreprise (abonnement à la plateforme).
 */
class PlatformInvoice extends Model
{
    public const OPEN = 'open';

    public const PAID = 'paid';

    public const VOID = 'void';

    protected $fillable = [
        'company_id', 'number', 'period', 'period_start', 'period_end', 'plan_name',
        'base_amount', 'included_orders', 'orders_count', 'overage_orders', 'overage_per_order', 'overage_amount',
        'total', 'amount_paid', 'status', 'issued_at', 'due_at', 'paid_at', 'last_reminder', 'notes', 'cycle',
        'pricing', 'deliveries_amount', 'cap_applied', 'carried_amount', 'carried_into_id',
        'extra_courier_days', 'extra_courier_price', 'extra_couriers_amount', 'premium_cards_count', 'premium_cards_amount',
        'app_options_amount', 'app_options',
    ];

    // Petite facture reportée sur la suivante (rien à payer à part)
    public const CARRIED = 'carried';

    public const MONTHLY = 'monthly';

    public const WEEKLY = 'weekly';

    protected function casts(): array
    {
        return [
            'period_start' => 'date:Y-m-d',
            'period_end' => 'date:Y-m-d',
            'issued_at' => 'date:Y-m-d',
            'due_at' => 'date:Y-m-d',
            'paid_at' => 'datetime',
            'base_amount' => 'integer',
            'included_orders' => 'integer',
            'orders_count' => 'integer',
            'overage_orders' => 'integer',
            'overage_per_order' => 'integer',
            'overage_amount' => 'integer',
            'total' => 'integer',
            'deliveries_amount' => 'integer',
            'carried_amount' => 'integer',
            'extra_courier_days' => 'integer',
            'extra_courier_price' => 'integer',
            'extra_couriers_amount' => 'integer',
            'premium_cards_count' => 'integer',
            'premium_cards_amount' => 'integer',
            'app_options_amount' => 'integer',
            'app_options' => 'array',
            'cap_applied' => 'boolean',
            'amount_paid' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Facture qui a repris le montant de cette petite facture reportée.
     */
    public function carriedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'carried_into_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PlatformPayment::class)->orderBy('paid_on')->orderBy('id');
    }

    /**
     * Période lisible : « octobre 2026 » ou « semaine du 19/10 au 25/10/2026 ».
     */
    public function periodLabel(): string
    {
        if ($this->cycle === self::WEEKLY) {
            return 'semaine du '.$this->period_start->format('d/m').' au '.$this->period_end->format('d/m/Y');
        }

        return $this->period_start->locale('fr')->translatedFormat('F Y');
    }

    public function scopeUnpaid(Builder $query): Builder
    {
        return $query->where('status', self::OPEN);
    }

    public function balance(): int
    {
        return max(0, $this->total - $this->amount_paid);
    }

    /**
     * Jours de retard (0 avant l'échéance comprise).
     */
    public function daysLate(?string $today = null): int
    {
        $today ??= now($this->company?->timezone ?? config('app.timezone'))->toDateString();

        return $this->status === self::OPEN && $this->due_at->toDateString() < $today
            ? (int) $this->due_at->diffInDays($today)
            : 0;
    }
}
