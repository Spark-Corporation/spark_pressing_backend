<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Écrans Figma « 06 — Caisse » : session de caisse journalière (ouverture, comptage,
 * double contrôle, clôture) et mouvements avec justificatif et double validation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pressing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->date('business_date');
            $table->timestamp('opened_at');
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('opening_balance')->default(0);
            $table->string('status', 16)->default('open');
            $table->json('counted')->nullable();
            $table->timestamp('counted_at')->nullable();
            $table->foreignId('counted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->bigInteger('difference')->default(0);
            $table->text('difference_reason')->nullable();
            $table->timestamp('controlled_at')->nullable();
            $table->foreignId('controlled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['agency_id', 'business_date']);
        });

        Schema::table('cash_movements', function (Blueprint $table) {
            $table->string('payment_method', 32)->default('cash');
            $table->string('beneficiary')->nullable();
            $table->string('reference', 64)->nullable();
            $table->text('comment')->nullable();
            $table->string('proof_path')->nullable();
            $table->boolean('requires_approval')->default(false);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('cash_session_id')->nullable()->constrained('cash_sessions')->nullOnDelete();
        });

        Schema::table('pressings', function (Blueprint $table) {
            $table->unsignedBigInteger('cash_double_control_threshold')->default(250000);
        });
    }

    public function down(): void
    {
        Schema::table('pressings', function (Blueprint $table) {
            $table->dropColumn('cash_double_control_threshold');
        });

        Schema::table('cash_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cash_session_id');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['payment_method', 'beneficiary', 'reference', 'comment', 'proof_path', 'requires_approval', 'approved_at']);
        });

        Schema::dropIfExists('cash_sessions');
    }
};
