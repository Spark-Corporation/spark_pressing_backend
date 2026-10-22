<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Figma « 17.03 — États du linge » : référentiel des défauts constatés à la réception. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laundry_statuses', function (Blueprint $table) {
            $table->string('code', 40)->nullable();
            $table->string('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->index(['pressing_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::table('laundry_statuses', function (Blueprint $table) {
            $table->dropIndex(['pressing_id', 'code']);
            $table->dropColumn(['code', 'description', 'sort_order']);
        });
    }
};
