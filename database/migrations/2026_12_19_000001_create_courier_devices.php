<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sonnerie des missions : téléphones des livreurs (application Android, notifications Firebase) et suivi des
 * sonneries sur chaque mission (première, rappel deux minutes après, réponse du livreur).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token', 512)->unique();
            $table->string('platform', 20)->default('android');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::table('order_assignments', function (Blueprint $table) {
            $table->unsignedTinyInteger('ring_count')->default(0);
            $table->timestamp('rang_at')->nullable();
            $table->timestamp('ring_answered_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('order_assignments', function (Blueprint $table) {
            $table->dropColumn(['ring_count', 'rang_at', 'ring_answered_at']);
        });
        Schema::dropIfExists('courier_devices');
    }
};
