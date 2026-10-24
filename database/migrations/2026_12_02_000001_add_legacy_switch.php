<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bascule depuis l'ancienne application : à partir de la date choisie par l'administrateur, un e-commerçant ou un
 * livreur connecté à l'ancienne application arrive sur Jibiat déjà connecté. Clé de bascule (sans date de fin,
 * révocable) collée dans l'ancienne application ; date de passage de chaque compte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->timestamp('legacy_switch_at')->nullable();
            $table->string('legacy_switch_key_hash', 64)->nullable()->unique();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('legacy_switched_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('legacy_switched_at');
        });
        Schema::table('companies', function (Blueprint $table) {
            $table->dropUnique(['legacy_switch_key_hash']);
            $table->dropColumn(['legacy_switch_at', 'legacy_switch_key_hash']);
        });
    }
};
