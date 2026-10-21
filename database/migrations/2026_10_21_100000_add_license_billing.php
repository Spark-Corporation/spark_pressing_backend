<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('licenses', function (Blueprint $table) {
            $table->string('reference', 32)->nullable()->unique()->after('code');
            $table->string('periodicity', 16)->default('annual')->after('plan');
            $table->boolean('auto_renew')->default(false)->after('periodicity');
            $table->unsignedInteger('max_agencies')->nullable()->after('seats');
            $table->unsignedInteger('storage_gb')->nullable()->after('max_agencies');
            $table->timestamp('period_started_at')->nullable()->after('activated_at');
            $table->timestamp('suspended_at')->nullable()->after('status');
            $table->string('suspension_reason')->nullable()->after('suspended_at');
        });

        Schema::create('license_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('license_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pressing_id')->constrained()->cascadeOnDelete();
            $table->string('plan', 32);
            $table->string('periodicity', 16);
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('taxes')->default(0);
            $table->char('currency', 3);
            $table->string('payment_method', 32);
            $table->string('reference', 64)->nullable();
            $table->string('invoice_number', 32)->nullable()->unique();
            $table->string('status', 16)->default('pending');
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->string('proof_path')->nullable();
            $table->string('recorded_by_type')->nullable();
            $table->unsignedBigInteger('recorded_by_id')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->index(['license_id', 'created_at']);
            $table->index(['status', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_payments');

        Schema::table('licenses', function (Blueprint $table) {
            $table->dropUnique(['reference']);
            $table->dropColumn([
                'reference', 'periodicity', 'auto_renew', 'max_agencies', 'storage_gb',
                'period_started_at', 'suspended_at', 'suspension_reason',
            ]);
        });
    }
};
