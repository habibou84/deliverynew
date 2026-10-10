<?php

namespace App\Models;

use App\Enums\AssignmentStatus;
use App\Enums\VehicleType;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\CourierFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Courier extends Model
{
    /** @use HasFactory<CourierFactory> */
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id',
        'user_id',
        'vehicle_type',
        'vehicle_plate',
        'pay_plan_id',
        'is_available',
        'current_lat',
        'current_lng',
        'last_location_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'vehicle_type' => VehicleType::class,
            'is_available' => 'boolean',
            'current_lat' => 'float',
            'current_lng' => 'float',
            'last_location_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function zones(): BelongsToMany
    {
        return $this->belongsToMany(Zone::class);
    }

    public function advances(): HasMany
    {
        return $this->hasMany(CourierAdvance::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(OrderExpense::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(OrderAssignment::class);
    }

    public function collections(): HasMany
    {
        return $this->hasMany(CashCollection::class);
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(CourierPayout::class);
    }

    /**
     * Plan choisi pour ce livreur (null : plan par défaut de l'entreprise).
     */
    public function payPlan(): BelongsTo
    {
        return $this->belongsTo(PayPlan::class);
    }

    public function earnings(): HasMany
    {
        return $this->hasMany(CourierEarning::class);
    }

    public function activeAssignments(): HasMany
    {
        return $this->assignments()->whereIn('status', AssignmentStatus::activeValues());
    }
}
