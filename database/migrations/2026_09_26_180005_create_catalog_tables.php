<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pressing_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('image')->nullable();
            $table->unsignedBigInteger('classic_price')->default(0);
            $table->unsignedBigInteger('express_price')->default(0);
            $table->unsignedBigInteger('repass_price')->default(0);
            $table->unsignedBigInteger('classic_price_kilo')->nullable();
            $table->unsignedBigInteger('express_price_kilo')->nullable();
            $table->unsignedBigInteger('repass_price_kilo')->nullable();
            $table->boolean('status')->default(true);
            $table->softDeletes();
            $table->timestamps();

            $table->index(['pressing_id', 'status']);
        });

        Schema::create('laundry_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pressing_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->boolean('status')->default(true);
            $table->timestamps();
        });

        Schema::create('renders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pressing_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('renders');
        Schema::dropIfExists('laundry_statuses');
        Schema::dropIfExists('articles');
    }
};
