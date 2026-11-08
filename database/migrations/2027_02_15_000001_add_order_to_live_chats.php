<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Après la confirmation, le client peut annuler ou changer l'adresse par WhatsApp : la conversation retient la course.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('live_chats', function (Blueprint $table) {
            $table->foreignId('order_id')->nullable()->after('zone_choices')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('live_chats', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_id');
        });
    }
};
