<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Applications à leur nom dans chaque formule : incluses, en option (prix par mois) ou non disponibles
        Schema::table('plans', function (Blueprint $table) {
            $table->json('apps')->nullable()->after('features');
        });

        // Options demandées par les entreprises (en attente, active, refusée, arrêtée)
        Schema::create('app_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 10);
            $table->string('status', 12)->default('pending');
            $table->unsignedInteger('price')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('stopped_at')->nullable();
            $table->string('note', 300)->nullable();
            $table->timestamps();
            $table->index(['company_id', 'platform', 'status']);
        });

        Schema::table('platform_invoices', function (Blueprint $table) {
            $table->unsignedInteger('app_options_amount')->default(0)->after('premium_cards_amount');
            $table->json('app_options')->nullable()->after('app_options_amount');
        });

        // Formules proposées au départ, si elles existent sous ces noms (modifiables ensuite dans la console)
        $defaults = [
            'Essentiel' => ['android' => 'option', 'android_price' => 10000, 'ios' => 'none', 'ios_price' => null],
            'Pro' => ['android' => 'included', 'android_price' => null, 'ios' => 'option', 'ios_price' => 15000],
            'Entreprise' => ['android' => 'included', 'android_price' => null, 'ios' => 'included', 'ios_price' => null],
        ];
        foreach ($defaults as $name => $apps) {
            DB::table('plans')->where('name', $name)->update(['apps' => json_encode($apps)]);
        }
    }

    public function down(): void
    {
        Schema::table('platform_invoices', fn (Blueprint $table) => $table->dropColumn(['app_options_amount', 'app_options']));
        Schema::dropIfExists('app_options');
        Schema::table('plans', fn (Blueprint $table) => $table->dropColumn('apps'));
    }
};
