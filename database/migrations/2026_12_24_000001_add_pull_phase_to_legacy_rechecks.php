<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recomparaison en deux temps : d'abord les commandes créées ou modifiées dans l'ancienne application depuis la date
 * (les nouvelles arrivent dans Jibiat), puis la relecture des courses déjà là, des plus récentes aux plus anciennes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legacy_rechecks', function (Blueprint $table) {
            $table->string('phase', 20)->default('compare'); // pull | compare
            $table->unsignedBigInteger('pull_after')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('legacy_rechecks', function (Blueprint $table) {
            $table->dropColumn(['phase', 'pull_after']);
        });
    }
};
