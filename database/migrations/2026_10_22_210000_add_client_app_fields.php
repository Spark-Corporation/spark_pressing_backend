<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Figma « 14 — Mobile client » : offres personnalisées, téléphone vérifié, mot de passe. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->boolean('consent_marketing')->default(false);
            $table->timestamp('phone_verified_at')->nullable();
            $table->timestamp('password_changed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['consent_marketing', 'phone_verified_at', 'password_changed_at']);
        });
    }
};
