<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Écrans Figma « 03 — Clients & fidélité » : identité nom/prénoms, relation client, consentements,
 * préférences de retrait, palier de fidélité forcé, archivage et notes internes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('contact_preference', 16)->nullable();
            $table->boolean('consent_sms')->default(false);
            $table->boolean('consent_email')->default(false);
            $table->timestamp('consent_updated_at')->nullable();
            $table->string('loyalty_tier', 16)->nullable();
            $table->string('pickup_slot')->nullable();
            $table->string('favorite_treatment')->nullable();
            $table->string('instructions')->nullable();
            $table->timestamp('archived_at')->nullable();
        });

        Schema::create('client_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pressing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->timestamps();
            $table->index(['client_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_notes');

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn([
                'first_name', 'last_name', 'contact_preference', 'consent_sms', 'consent_email',
                'consent_updated_at', 'loyalty_tier', 'pickup_slot', 'favorite_treatment',
                'instructions', 'archived_at',
            ]);
        });
    }
};
