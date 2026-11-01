<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Consommation de Jibiat IA d'une entreprise : un mois, une fonction (appels, jetons, coût réel en millièmes de F) */
class AiUsage extends Model
{
    protected $fillable = ['company_id', 'month', 'feature', 'calls', 'input_tokens', 'output_tokens', 'voice_seconds', 'cost_millis'];

    protected function casts(): array
    {
        return ['calls' => 'integer', 'input_tokens' => 'integer', 'output_tokens' => 'integer', 'voice_seconds' => 'integer', 'cost_millis' => 'integer'];
    }
}
