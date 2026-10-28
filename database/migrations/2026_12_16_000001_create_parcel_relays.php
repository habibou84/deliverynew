<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Relais obligatoire : un colis chez un autre livreur doit lui être remis (relais) avant le départ en livraison
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('relay_required')->default(true)->after('merchant_edit_approval');
        });

        // Relais entre livreurs : celui qui a les colis donne un code à 4 chiffres, celui qui les reçoit le saisit
        Schema::create('parcel_relays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_courier_id')->constrained('couriers')->cascadeOnDelete();
            $table->foreignId('to_courier_id')->constrained('couriers')->cascadeOnDelete();
            // pending (code donné), confirmed (colis reçus), cancelled (remplacé ou expiré)
            $table->string('status', 12)->default('pending');
            $table->text('code');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->json('order_ids');
            $table->json('received_ids')->nullable();
            $table->json('missing_ids')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('confirmed_at')->nullable();
            // Validé par le dispatch à la place des livreurs (téléphone en panne) : qui et pourquoi
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 255)->nullable();
            $table->decimal('from_lat', 10, 7)->nullable();
            $table->decimal('from_lng', 10, 7)->nullable();
            $table->decimal('to_lat', 10, 7)->nullable();
            $table->decimal('to_lng', 10, 7)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['to_courier_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parcel_relays');
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('relay_required'));
    }
};
