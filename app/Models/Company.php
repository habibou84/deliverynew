<?php

namespace App\Models;

use App\Enums\CompanyStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    /** @use HasFactory<\Database\Factories\CompanyFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'phone',
        'email',
        'address',
        'currency',
        'timezone',
        'auto_confirm_orders',
        'default_max_attempts',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'auto_confirm_orders' => 'boolean',
            'default_max_attempts' => 'integer',
            'status' => CompanyStatus::class,
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === CompanyStatus::Active;
    }
}
