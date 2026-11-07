<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quartier mal écrit qui ressemble à plusieurs quartiers : le client choisit parmi eux.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('live_chats', function (Blueprint $table) {
            // [zone_id, …] proposés au client
            $table->json('zone_choices')->nullable()->after('zone_id');
        });
    }

    public function down(): void
    {
        Schema::table('live_chats', function (Blueprint $table) {
            $table->dropColumn('zone_choices');
        });
    }
};
