<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tournée du livreur : ordre de passage de ses livraisons du jour, choisi par lui (tri de proche en proche ou à la
 * main). Vide pour une mission pas encore placée : elle passe après les autres.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_assignments', function (Blueprint $table) {
            $table->unsignedSmallInteger('route_rank')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('order_assignments', fn (Blueprint $table) => $table->dropColumn('route_rank'));
    }
};
