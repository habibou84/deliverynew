<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Lien public du live : les clientes voient les produits et commandent sans WhatsApp.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lives', function (Blueprint $table) {
            $table->string('public_token', 16)->nullable()->unique();
        });

        DB::table('lives')->whereNull('public_token')->orderBy('id')->pluck('id')->each(
            fn ($id) => DB::table('lives')->where('id', $id)->update(['public_token' => Str::lower(Str::random(10))])
        );
    }

    public function down(): void
    {
        Schema::table('lives', function (Blueprint $table) {
            $table->dropUnique(['public_token']);
            $table->dropColumn('public_token');
        });
    }
};
