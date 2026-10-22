<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Champs exposés par les écrans Figma « Dépôts & POS » : express par ligne, défauts constatés,
 * notes internes, terminal POS, emplacement de rangement, mentions légales du ticket / facture.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pressings', function (Blueprint $table) {
            $table->unsignedSmallInteger('express_surcharge_percent')->default(100);
            $table->string('slogan')->nullable();
            $table->text('invoice_terms')->nullable();
        });

        Schema::table('agencies', function (Blueprint $table) {
            $table->string('email')->nullable();
            $table->string('rccm', 64)->nullable();
            $table->string('tax_id', 64)->nullable();
        });

        Schema::table('deposits', function (Blueprint $table) {
            $table->boolean('is_express')->default(false);
            $table->unsignedBigInteger('express_supplement')->default(0);
            $table->string('terminal', 32)->nullable();
            $table->string('local_ref', 40)->nullable();
            $table->string('storage_location', 64)->nullable();
            $table->string('discount_reason')->nullable();
            $table->string('cancel_reason')->nullable();
        });

        Schema::table('deposit_units', function (Blueprint $table) {
            $table->boolean('is_express')->default(false);
            $table->unsignedBigInteger('express_supplement')->default(0);
            $table->string('color', 64)->nullable();
            $table->json('defects')->nullable();
            $table->text('internal_notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('deposit_units', function (Blueprint $table) {
            $table->dropColumn(['is_express', 'express_supplement', 'color', 'defects', 'internal_notes']);
        });

        Schema::table('deposits', function (Blueprint $table) {
            $table->dropColumn([
                'is_express', 'express_supplement', 'terminal', 'local_ref',
                'storage_location', 'discount_reason', 'cancel_reason',
            ]);
        });

        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn(['email', 'rccm', 'tax_id']);
        });

        Schema::table('pressings', function (Blueprint $table) {
            $table->dropColumn(['express_surcharge_percent', 'slogan', 'invoice_terms']);
        });
    }
};
