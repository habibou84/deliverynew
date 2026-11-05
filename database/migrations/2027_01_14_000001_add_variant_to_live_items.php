<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Variante d'un produit du live (taille, couleur) : chaque variante a son propre code (A1, A2…) */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('live_items', function (Blueprint $table) {
            $table->string('variant', 40)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('live_items', fn (Blueprint $t) => $t->dropColumn('variant'));
    }
};
