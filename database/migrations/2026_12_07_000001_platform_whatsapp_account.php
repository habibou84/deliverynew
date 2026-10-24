<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Numéro officiel de Jibiat (API Cloud de Meta, saisi dans la console) : compte « platform », sans entreprise
        Schema::table('whatsapp_accounts', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('whatsapp_accounts')->whereNull('company_id')->delete();
        Schema::table('whatsapp_accounts', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable(false)->change();
        });
    }
};
