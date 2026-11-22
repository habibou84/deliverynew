<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Colis en retour réaffecté à une autre course du même e-commerçant : lien entre les deux courses, et changement
 * d'étiquette confirmé sur la nouvelle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('reassigned_to_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('reassigned_from_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->timestamp('label_confirmed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reassigned_to_order_id');
            $table->dropConstrainedForeignId('reassigned_from_order_id');
            $table->dropColumn('label_confirmed_at');
        });
    }
};
