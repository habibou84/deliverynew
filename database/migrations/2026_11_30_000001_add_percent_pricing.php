<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Formules « à la livraison » : un pourcentage des frais de livraison de chaque course prise en charge (avec un
 * minimum et un maximum par course), un taux plus bas au-delà d'un volume, un plafond par mois ; petites factures
 * reportées sur la suivante (toutes formules).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            // flat : forfait et courses en plus ; percent : pourcentage des frais de livraison
            $table->string('pricing', 10)->default('flat');
            // Taux en centièmes de pour cent (100 = 1 %)
            $table->unsignedSmallInteger('percent_rate')->nullable();
            $table->unsignedInteger('percent_min')->default(0);
            $table->unsignedInteger('percent_max')->nullable();
            // Au-delà de ce nombre de courses dans le mois : taux plus bas
            $table->unsignedInteger('volume_threshold')->nullable();
            $table->unsignedSmallInteger('volume_rate')->nullable();
            // Jamais plus que ce montant par mois (ramené à la semaine pour une facture de la semaine)
            $table->unsignedInteger('cap_amount')->nullable();
            // Facture plus petite : reportée sur la suivante
            $table->unsignedInteger('min_invoice')->nullable();
        });

        Schema::table('platform_invoices', function (Blueprint $table) {
            $table->string('pricing', 10)->default('flat');
            $table->unsignedInteger('deliveries_amount')->default(0);
            $table->boolean('cap_applied')->default(false);
            // Montant reporté des petites factures précédentes, et facture où une petite facture a été reportée
            $table->unsignedInteger('carried_amount')->default(0);
            $table->foreignId('carried_into_id')->nullable()->constrained('platform_invoices')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('platform_invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('carried_into_id');
            $table->dropColumn(['pricing', 'deliveries_amount', 'cap_applied', 'carried_amount']);
        });
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['pricing', 'percent_rate', 'percent_min', 'percent_max', 'volume_threshold', 'volume_rate', 'cap_amount', 'min_invoice']);
        });
    }
};
