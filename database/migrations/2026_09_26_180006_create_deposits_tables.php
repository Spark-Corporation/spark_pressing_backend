<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deposits', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_uuid')->unique();
            $table->string('code');
            $table->foreignId('pressing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('laveur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('classeur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('receiver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('deposit_date');
            $table->timestamp('retrieve_date')->nullable();
            $table->timestamp('retrieved_at')->nullable();
            $table->unsignedBigInteger('subtotal')->default(0);
            $table->unsignedBigInteger('discount')->default(0);
            $table->unsignedBigInteger('total')->default(0);
            $table->unsignedBigInteger('advanced')->default(0);
            $table->unsignedBigInteger('left_to_pay')->default(0);
            $table->string('payment_method', 32)->nullable();
            $table->boolean('status')->default(true);
            $table->string('etat', 24)->default('waiting');
            $table->string('receiver_name')->nullable();
            $table->text('notes')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['agency_id', 'code']);
            $table->index(['pressing_id', 'agency_id', 'status']);
            $table->index(['agency_id', 'etat']);
            $table->index('deposit_date');
            $table->index('retrieve_date');
        });

        Schema::create('deposit_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deposit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pressing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('article_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('designation');
            $table->string('pricing_type', 16)->default('piece');
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('weight_kg', 8, 3)->nullable();
            $table->unsignedTinyInteger('type_action')->default(0);
            $table->unsignedBigInteger('unit_price')->default(0);
            $table->unsignedBigInteger('line_total')->default(0);
            $table->unsignedInteger('retrieve_quantity')->default(0);
            $table->string('state')->nullable();
            $table->foreignId('render_id')->nullable()->constrained('renders')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['deposit_id']);
        });

        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_uuid')->unique();
            $table->foreignId('deposit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pressing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('amount');
            $table->string('type', 16)->default('in');
            $table->string('payment_method', 32)->nullable();
            $table->timestamp('transaction_date');
            $table->softDeletes();
            $table->timestamps();

            $table->index(['agency_id', 'transaction_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('deposit_units');
        Schema::dropIfExists('deposits');
    }
};
