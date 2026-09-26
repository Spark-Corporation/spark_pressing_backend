<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pressing_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('lavage_hour');
            $table->unsignedInteger('express_hour');
            $table->unsignedInteger('repassage_hour');
            $table->boolean('status')->default(true);
            $table->timestamps();
            $table->index(['pressing_id', 'status']);
        });

        Schema::create('code_suffixes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pressing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->string('title', 32);
            $table->boolean('status')->default(true);
            $table->timestamps();
            $table->index(['pressing_id', 'agency_id']);
        });

        Schema::create('licenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pressing_id')->constrained()->cascadeOnDelete();
            $table->string('plan', 32)->default('standard');
            $table->unsignedInteger('seats')->default(5);
            $table->string('code')->nullable();
            $table->boolean('is_activated')->default(false);
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();
            $table->index(['pressing_id', 'is_activated', 'expires_at']);
        });

        Schema::create('promo_specials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pressing_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->unsignedTinyInteger('rate_percent');
            $table->unsignedInteger('max_clients')->nullable();
            $table->unsignedInteger('used')->default(0);
            $table->timestamp('ends_at')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();
            $table->unique(['pressing_id', 'code']);
        });

        Schema::create('wallet_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pressing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('deposit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 16);
            $table->integer('amount');
            $table->string('reason')->nullable();
            $table->timestamps();
            $table->index(['pressing_id', 'client_id']);
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->unsignedBigInteger('wallet_balance')->default(0);
            $table->string('sponsor_code', 12)->nullable();
            $table->foreignId('referred_by_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->timestamp('last_deposit_at')->nullable();
            $table->unique(['pressing_id', 'sponsor_code']);
            $table->index(['pressing_id', 'last_deposit_at']);
        });

        Schema::table('deposits', function (Blueprint $table) {
            $table->index(['pressing_id', 'retrieve_date', 'status'], 'deposits_due_idx');
            $table->index(['pressing_id', 'retrieved_at'], 'deposits_retrieved_idx');
            $table->index(['pressing_id', 'left_to_pay', 'status'], 'deposits_unpaid_idx');
            $table->index(['pressing_id', 'client_id', 'deposit_date'], 'deposits_client_date_idx');
            $table->index(['pressing_id', 'discount'], 'deposits_discount_idx');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->index(['pressing_id', 'transaction_date'], 'transactions_pressing_date_idx');
        });

        Schema::table('cash_movements', function (Blueprint $table) {
            $table->index(['pressing_id', 'validated', 'type', 'action_date'], 'cash_movements_report_idx');
        });
    }

    public function down(): void
    {
        Schema::table('cash_movements', function (Blueprint $table) {
            $table->dropIndex('cash_movements_report_idx');
        });
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('transactions_pressing_date_idx');
        });
        Schema::table('deposits', function (Blueprint $table) {
            $table->dropIndex('deposits_due_idx');
            $table->dropIndex('deposits_retrieved_idx');
            $table->dropIndex('deposits_unpaid_idx');
            $table->dropIndex('deposits_client_date_idx');
            $table->dropIndex('deposits_discount_idx');
        });
        Schema::table('clients', function (Blueprint $table) {
            $table->dropUnique(['pressing_id', 'sponsor_code']);
            $table->dropIndex(['pressing_id', 'last_deposit_at']);
            $table->dropConstrainedForeignId('referred_by_id');
            $table->dropColumn(['wallet_balance', 'sponsor_code', 'last_deposit_at']);
        });
        Schema::dropIfExists('wallet_ledger');
        Schema::dropIfExists('promo_specials');
        Schema::dropIfExists('licenses');
        Schema::dropIfExists('code_suffixes');
        Schema::dropIfExists('delivery_hours');
    }
};
