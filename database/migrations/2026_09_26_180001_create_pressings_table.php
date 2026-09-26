<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pressings', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('details')->nullable();
            $table->boolean('status')->default(true);
            $table->string('pricing_mode', 16)->default('piece');
            $table->boolean('workflow_laveur_enabled')->default(true);
            $table->boolean('workflow_classeur_enabled')->default(true);
            $table->boolean('block_retrieve_if_unpaid')->default(true);
            $table->unsignedInteger('loyalty_points_rate')->default(0);
            $table->unsignedSmallInteger('hours_classic')->default(48);
            $table->unsignedSmallInteger('hours_express')->default(24);
            $table->unsignedSmallInteger('hours_repass')->default(12);
            $table->string('primary_color', 16)->nullable();
            $table->string('secondary_color', 16)->nullable();
            $table->string('logo_path')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pressings');
    }
};
