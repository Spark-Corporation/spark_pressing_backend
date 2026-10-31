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
            if (! Schema::hasColumn('pressings', 'monthly_revenue_target')) {
                $table->unsignedBigInteger('monthly_revenue_target')->nullable();
            }
            if (! Schema::hasColumn('pressings', 'cash_gap_tolerance')) {
                $table->unsignedBigInteger('cash_gap_tolerance')->default(5000);
            }
            if (! Schema::hasColumn('pressings', 'sensitive_discount_percent')) {
                $table->unsignedTinyInteger('sensitive_discount_percent')->default(15);
            }
        });

        if (! Schema::hasTable('report_schedules')) {
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
                // dateTime : MySQL STRICT refuse un 2e TIMESTAMP NOT NULL sans défaut explicite.
                $table->dateTime('next_run_at');
                $table->boolean('active')->default(true);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('report_schedules');

        Schema::table('pressings', function (Blueprint $table) {
            $drop = array_values(array_filter(
                ['monthly_revenue_target', 'cash_gap_tolerance', 'sensitive_discount_percent'],
                fn (string $c) => Schema::hasColumn('pressings', $c)
            ));
            if ($drop) {
                $table->dropColumn($drop);
            }
        });
    }
};
