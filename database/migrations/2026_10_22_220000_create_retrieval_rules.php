<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Figma « 17.02 — Heures de retrait » : délai de disponibilité par agence et prestation. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retrieval_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pressing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agency_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('service', 16);
            $table->unsignedSmallInteger('hours');
            $table->boolean('status')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['pressing_id', 'agency_id', 'service']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retrieval_rules');
    }
};
