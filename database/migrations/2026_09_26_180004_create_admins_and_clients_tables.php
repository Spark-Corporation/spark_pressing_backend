<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admins', function (Blueprint $table) {
            $table->id();
            $table->string('fullname');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('picture')->nullable();
            $table->boolean('status')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pressing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agency_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code')->nullable();
            $table->string('fullname');
            $table->string('email')->nullable();
            $table->string('phone_number');
            $table->string('fixe_number')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->date('birthday')->nullable();
            $table->string('picture')->nullable();
            $table->string('password')->nullable();
            $table->unsignedInteger('loyalty_points')->default(0);
            $table->boolean('status')->default(true);
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['pressing_id', 'phone_number']);
            $table->index(['pressing_id', 'fullname']);
            $table->index('phone_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
        Schema::dropIfExists('admins');
    }
};
