<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pressing_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('contact')->nullable();
            $table->string('country_code', 2)->default('CM');
            $table->string('code_prefix', 8)->nullable();
            $table->string('code_suffix', 8)->nullable();
            $table->unsignedInteger('last_deposit_sequence')->default(0);
            $table->boolean('status')->default(true);
            $table->softDeletes();
            $table->timestamps();

            $table->index(['pressing_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agencies');
    }
};
