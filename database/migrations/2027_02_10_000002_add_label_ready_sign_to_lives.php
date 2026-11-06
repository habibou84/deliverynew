<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Étape « L'étiquette » cochée (« C'est fait ») : empreinte des produits du live à ce moment-là. Si les produits,
     * leurs prix ou leurs quantités changent, l'étiquette est à refaire.
     */
    public function up(): void
    {
        Schema::table('lives', function (Blueprint $table) {
            $table->string('label_ready_sign', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('lives', fn (Blueprint $table) => $table->dropColumn('label_ready_sign'));
    }
};
