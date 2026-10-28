<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Applications Android au nom de l'entreprise (console › Applications à leur nom) : réglages par entreprise, clé de
 * signature gardée par Jibiat, versions fabriquées par GitHub Actions. Et demandes de suppression de compte des
 * e-commerçants et des livreurs (exigées par Google Play).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branded_apps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->string('merchant_app_id', 150);
            $table->string('courier_app_id', 150);
            $table->string('merchant_app_name', 30)->nullable();
            $table->string('courier_app_name', 30)->nullable();
            $table->timestamp('google_linked_at')->nullable();
            $table->string('short_description', 80)->nullable();
            $table->text('full_description')->nullable();
            $table->text('keystore')->nullable(); // PKCS12 en base64, chiffré
            $table->text('keystore_password')->nullable(); // chiffré
            $table->string('key_alias', 40)->default('upload');
            $table->timestamp('keystore_created_at')->nullable();
            $table->unsignedInteger('next_version_code')->default(1);
            $table->timestamps();
        });

        Schema::create('branded_app_builds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branded_app_id')->constrained()->cascadeOnDelete();
            $table->string('version_name', 20);
            $table->unsignedInteger('version_code');
            $table->string('status', 16)->default('queued'); // queued | building | ready | failed | published
            $table->string('token_hash', 64)->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->string('run_url')->nullable();
            $table->json('files')->nullable(); // {"ecommercant": {"aab": "…", "apk": "…"}, "livreur": {…}}
            $table->string('error', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['branded_app_id', 'version_code']);
        });

        Schema::create('account_deletion_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('role', 40)->nullable();
            $table->timestamp('erase_after');
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('erased_at')->nullable();
            $table->timestamps();
            $table->index(['erase_after', 'cancelled_at', 'erased_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_deletion_requests');
        Schema::dropIfExists('branded_app_builds');
        Schema::dropIfExists('branded_apps');
    }
};
