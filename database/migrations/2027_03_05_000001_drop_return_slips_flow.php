<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fin des bons de retour : la remise au marchand se fait seulement par le QR code du livreur (plus sûr qu'une
 * signature). Les bons encore ouverts sont fermés ; leurs colis gardent leur mission de retour et se remettent par
 * QR code. La confirmation du marchand devient la règle pour toutes les entreprises (réglage gardé).
 * Les bons déjà remis restent en base (historique).
 */
return new class extends Migration
{
    public function up(): void
    {
        $open = DB::table('return_slips')->where('status', 'open')->pluck('id');
        foreach ($open->chunk(500) as $chunk) {
            DB::table('orders')->whereIn('return_slip_id', $chunk->all())->update(['return_slip_id' => null]);
            DB::table('return_slips')->whereIn('id', $chunk->all())->update(['status' => 'cancelled', 'updated_at' => now()]);
        }

        DB::table('companies')->update(['return_confirmation' => true]);
        Schema::table('companies', function ($table) {
            $table->boolean('return_confirmation')->default(true)->change();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function ($table) {
            $table->boolean('return_confirmation')->default(false)->change();
        });
    }
};
