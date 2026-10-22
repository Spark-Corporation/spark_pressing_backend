<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Figma « 10 — Multi-agences » et « 12 — Synchronisation » : horaires et objectif par agence, lots de synchronisation. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->string('opens_at', 5)->nullable();
            $table->string('closes_at', 5)->nullable();
            $table->unsignedBigInteger('monthly_revenue_target')->nullable();
        });

        Schema::create('sync_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pressing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agency_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference', 40);
            $table->string('device_code', 40)->nullable();
            $table->unsignedInteger('operations')->default(0);
            $table->unsignedInteger('succeeded')->default(0);
            $table->unsignedInteger('conflicts')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->string('status', 16)->default('completed');
            $table->json('results')->nullable();
            $table->timestamps();
            $table->index(['pressing_id', 'agency_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_batches');
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn(['opens_at', 'closes_at', 'monthly_revenue_target']);
        });
    }
};
