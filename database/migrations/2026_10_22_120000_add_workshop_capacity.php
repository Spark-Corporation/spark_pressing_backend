<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** « Capacité atelier · 18 / 24 » (écran Atelier Kanban). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pressings', function (Blueprint $table) {
            $table->unsignedInteger('workshop_capacity')->default(24);
        });
    }

    public function down(): void
    {
        Schema::table('pressings', function (Blueprint $table) {
            $table->dropColumn('workshop_capacity');
        });
    }
};
