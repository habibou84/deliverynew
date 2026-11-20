<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prix de base de la grille : appliqué à toute destination (commune ou quartier) qui n'a pas de prix, ni par elle-même
 * ni par sa commune, et à aucun trajet particulier. Plus de course bloquée faute de tarif.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pricing_grids', function (Blueprint $table) {
            $table->unsignedInteger('base_price')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pricing_grids', fn (Blueprint $table) => $table->dropColumn('base_price'));
    }
};
