<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_movement_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pressing_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('direction', 8);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });

        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_uuid')->unique();
            $table->foreignId('pressing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('cash_movement_categories')->nullOnDelete();
            $table->string('label');
            $table->string('type', 8);
            $table->unsignedBigInteger('amount');
            $table->timestamp('action_date');
            $table->boolean('validated')->default(false);
            $table->softDeletes();
            $table->timestamps();

            $table->index(['agency_id', 'action_date']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pressing_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('agency_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor_type')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action');
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('payload')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['pressing_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('cash_movement_categories');
    }
};
