<?php

use App\Services\Zones\StandardZones;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zones standard : chaque zone porte le code de son lieu dans la liste commune du socle (null : zone propre à
 * l'entreprise), les anciens noms reconnus et le prix de l'ancienne application. Prix par destination « depuis
 * partout » par groupes de lieux. Fusions de zones annulables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zones', function (Blueprint $table) {
            $table->string('place_code', 120)->nullable()->after('parent_id');
            $table->json('aliases')->nullable()->after('name');
            $table->unsignedInteger('legacy_price')->nullable();
            $table->timestamp('legacy_price_done_at')->nullable();
            $table->timestamp('place_checked_at')->nullable();
            $table->foreignId('merged_into_id')->nullable()->constrained('zones')->nullOnDelete();
            $table->index(['company_id', 'place_code']);
        });

        Schema::create('pricing_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pricing_grid_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('price');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('pricing_group_zone', function (Blueprint $table) {
            $table->foreignId('pricing_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('zone_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pricing_grid_id')->constrained()->cascadeOnDelete();
            $table->primary(['pricing_group_id', 'zone_id']);
            // Un lieu n'est que dans un groupe par grille
            $table->unique(['pricing_grid_id', 'zone_id']);
        });

        Schema::create('zone_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('zone_id')->constrained()->cascadeOnDelete();
            $table->foreignId('into_zone_id')->nullable()->constrained('zones')->nullOnDelete();
            $table->string('action', 16); // link | merge
            $table->string('place_code', 120)->nullable();
            $table->json('before');
            $table->string('status', 16)->default('done'); // done | undone
            $table->timestamp('undone_at')->nullable();
            $table->timestamps();
        });

        // Entreprises existantes : lieux standard rattachés ou ajoutés
        app(StandardZones::class)->syncAll();
    }

    public function down(): void
    {
        Schema::dropIfExists('zone_links');
        Schema::dropIfExists('pricing_group_zone');
        Schema::dropIfExists('pricing_groups');
        Schema::table('zones', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'place_code']);
            $table->dropConstrainedForeignId('merged_into_id');
            $table->dropColumn(['place_code', 'aliases', 'legacy_price', 'legacy_price_done_at', 'place_checked_at']);
        });
    }
};
