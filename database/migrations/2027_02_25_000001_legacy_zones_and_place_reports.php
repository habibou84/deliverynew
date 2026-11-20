<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lieux : un seul référentiel (liste commune du socle).
 * - Les zones créées par la reprise de l'ancienne application (sans lieu du référentiel) sont marquées « legacy » :
 *   elles sortent des destinations et ne servent plus qu'aux commandes reprises (rubrique à part).
 * - Quartiers manquants signalés par les entreprises et leurs e-commerçants, ajoutés ensuite au référentiel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zones', function (Blueprint $table) {
            $table->boolean('legacy')->default(false);
        });

        // Zones propres (sans lieu) d'une entreprise qui a fait la reprise : adresses de l'ancienne application
        $legacyCompanies = DB::table('companies')->whereNotNull('legacy_import_used_at')->pluck('id');
        if ($legacyCompanies->isNotEmpty()) {
            DB::table('zones')->whereIn('company_id', $legacyCompanies)->whereNull('place_code')->whereNull('merged_into_id')
                ->where('default_shipping', false)->where('is_shipping', false)->update(['legacy' => true]);
        }

        Schema::create('place_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120);
            // Commune ou ville où se trouve le quartier (code du référentiel)
            $table->string('parent_code', 120)->nullable();
            $table->string('note', 500)->nullable();
            // pending, added, rejected
            $table->string('status', 20)->default('pending');
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('place_reports');
        Schema::table('zones', fn (Blueprint $table) => $table->dropColumn('legacy'));
    }
};
