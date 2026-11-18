<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zones de ramassage : l'entreprise ne ramasse pas forcément partout où elle livre. Par défaut, partout.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zones', function (Blueprint $table) {
            $table->boolean('is_pickup')->default(true)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('zones', fn (Blueprint $table) => $table->dropColumn('is_pickup'));
    }
};
