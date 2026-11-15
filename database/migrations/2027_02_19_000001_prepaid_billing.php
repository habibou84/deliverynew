<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tout prépayé :
 * - facture d'abonnement émise avant la période (« prepaid »), à payer au premier jour ;
 * - achats payés tout de suite (« kind ») : livreur en plus, pack de courses, pack de points IA ; plusieurs par
 *   période, d'où la fin de l'unicité entreprise + période (gardée pour l'abonnement, dans le code) ;
 * - livreurs en plus achetés par l'entreprise, repris à chaque renouvellement (ceux déjà actifs au-delà de la
 *   formule sont gardés) ;
 * - formule « À la livraison » (pourcentage, pas payable d'avance) retirée des nouvelles inscriptions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_invoices', function (Blueprint $table) {
            // subscription (abonnement de la période), courier, orders, ai_points (achats)
            $table->string('kind', 20)->default('subscription');
            $table->boolean('prepaid')->default(false);
            // Achat : quantité, prix unitaire, libellé ; « applied » une fois pris en compte
            $table->json('purchase')->nullable();
            $table->index(['company_id', 'period', 'kind']);
        });
        Schema::table('platform_invoices', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'period']);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->unsignedSmallInteger('extra_couriers')->default(0);
        });

        // Livreurs déjà actifs au-delà de la formule (dernier relevé) : gardés comme livreurs en plus
        $plans = DB::table('plans')->whereNotNull('max_couriers')->whereNotNull('extra_courier_price')->pluck('max_couriers', 'id');
        foreach (DB::table('companies')->whereIn('plan_id', $plans->keys())->get(['id', 'plan_id']) as $company) {
            $last = DB::table('courier_counts')->where('company_id', $company->id)->orderByDesc('day')->value('couriers');
            $extra = max(0, (int) $last - (int) $plans[$company->plan_id]);
            if ($extra > 0) {
                DB::table('companies')->where('id', $company->id)->update(['extra_couriers' => $extra]);
            }
        }

        DB::table('plans')->where('pricing', 'percent')->update(['is_active' => false]);
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('extra_couriers');
        });
        Schema::table('platform_invoices', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'period', 'kind']);
            $table->dropColumn(['kind', 'prepaid', 'purchase']);
            $table->unique(['company_id', 'period']);
        });
    }
};
