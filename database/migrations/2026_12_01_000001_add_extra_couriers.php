<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Livreurs en plus de ceux de la formule : prix par mois de chaque livreur en plus (vide : pas de livreur en plus),
 * relevé du nombre de livreurs actifs de chaque jour, et leur ligne sur la facture.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedInteger('extra_courier_price')->nullable();
        });

        // Plus grand nombre de livreurs actifs de chaque jour, pour facturer les livreurs en plus au prorata des jours
        Schema::create('courier_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->unsignedInteger('couriers');
            $table->timestamps();
            $table->unique(['company_id', 'day']);
        });

        Schema::table('platform_invoices', function (Blueprint $table) {
            // Somme, sur les jours facturés, des livreurs au-delà de la formule (2 livreurs en plus pendant 10 jours = 20)
            $table->unsignedInteger('extra_courier_days')->default(0);
            $table->unsignedInteger('extra_courier_price')->default(0);
            $table->unsignedInteger('extra_couriers_amount')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('platform_invoices', function (Blueprint $table) {
            $table->dropColumn(['extra_courier_days', 'extra_courier_price', 'extra_couriers_amount']);
        });
        Schema::dropIfExists('courier_counts');
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('extra_courier_price');
        });
    }
};
