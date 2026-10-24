<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Parc de l'entreprise : une fiche par véhicule, confié à un livreur (ou à personne)
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20)->default('moto');      // moto | velo | voiture | tricycle
            $table->string('plate', 30)->nullable();
            $table->string('brand', 60)->nullable();
            $table->string('model', 60)->nullable();
            $table->string('color', 40)->nullable();
            $table->string('photo_path')->nullable();
            $table->string('owner', 20)->nullable();           // company | courier | lessor (null : à préciser)
            $table->string('owner_name', 120)->nullable();     // loueur ou propriétaire
            $table->foreignId('courier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('active');   // active | repair | retired
            $table->string('notes', 1000)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'plate']);
        });

        // Qui a eu quel véhicule, et quand
        Schema::create('vehicle_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('courier_id')->constrained()->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();

            $table->index(['vehicle_id', 'started_at']);
        });

        // Papiers avec leur date de fin : ceux du véhicule (carte grise, assurance…) ou du livreur (permis)
        Schema::create('vehicle_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('courier_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 30);                        // registration | insurance | inspection | tax | license | other
            $table->string('number', 60)->nullable();
            $table->date('expires_at')->nullable();
            $table->string('file_path')->nullable();
            $table->string('file_mime', 100)->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamp('warned_at')->nullable();        // rappel 15 jours avant
            $table->timestamp('expired_warned_at')->nullable(); // rappel le jour de la fin
            $table->timestamps();

            $table->index(['company_id', 'expires_at']);
        });

        // Reprise : chaque livreur qui a une plaque reçoit sa fiche véhicule
        $now = now();
        DB::table('couriers')->whereNotNull('vehicle_plate')->where('vehicle_plate', '!=', '')
            ->orderBy('id')->each(function ($courier) use ($now) {
                $id = DB::table('vehicles')->insertGetId([
                    'company_id' => $courier->company_id,
                    'type' => in_array($courier->vehicle_type, ['moto', 'velo', 'voiture', 'tricycle'], true) ? $courier->vehicle_type : 'moto',
                    'plate' => mb_strtoupper(trim($courier->vehicle_plate)),
                    'courier_id' => $courier->id,
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                DB::table('vehicle_assignments')->insert([
                    'company_id' => $courier->company_id,
                    'vehicle_id' => $id,
                    'courier_id' => $courier->id,
                    'started_at' => $now,
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_documents');
        Schema::dropIfExists('vehicle_assignments');
        Schema::dropIfExists('vehicles');
    }
};
