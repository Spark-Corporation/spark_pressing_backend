<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Écrans Figma « 08 — Rapports & bilans » : objectif de CA, seuil d’écart de caisse, rapports programmés. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pressings', function (Blueprint $table) {
            $table->unsignedBigInteger('monthly_revenue_target')->nullable();
            $table->unsignedBigInteger('cash_gap_tolerance')->default(5000);
            $table->unsignedTinyInteger('sensitive_discount_percent')->default(15);
        });

        Schema::create('report_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pressing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('agency_id')->nullable()->constrained()->nullOnDelete();
            $table->string('report', 32);
            $table->string('frequency', 16);
            $table->string('format', 8)->default('csv');
            $table->json('recipients');
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('next_run_at');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_schedules');

        Schema::table('pressings', function (Blueprint $table) {
            $table->dropColumn(['monthly_revenue_target', 'cash_gap_tolerance', 'sensitive_discount_percent']);
        });
    }
};
