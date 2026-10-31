<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Idempotent : en prod certaines colonnes existaient déjà hors migrations table
        // (échec post-deploy « Duplicate column periodicity » qui bloquait toute la suite).
        Schema::table('licenses', function (Blueprint $table) {
            if (! Schema::hasColumn('licenses', 'reference')) {
                $table->string('reference', 32)->nullable()->unique()->after('code');
            }
            if (! Schema::hasColumn('licenses', 'periodicity')) {
                $table->string('periodicity', 16)->default('annual')->after('plan');
            }
            if (! Schema::hasColumn('licenses', 'auto_renew')) {
                $table->boolean('auto_renew')->default(false)->after('periodicity');
            }
            if (! Schema::hasColumn('licenses', 'max_agencies')) {
                $table->unsignedInteger('max_agencies')->nullable()->after('seats');
            }
            if (! Schema::hasColumn('licenses', 'storage_gb')) {
                $table->unsignedInteger('storage_gb')->nullable()->after('max_agencies');
            }
            if (! Schema::hasColumn('licenses', 'period_started_at')) {
                $table->timestamp('period_started_at')->nullable()->after('activated_at');
            }
            if (! Schema::hasColumn('licenses', 'suspended_at')) {
                $table->timestamp('suspended_at')->nullable()->after('status');
            }
            if (! Schema::hasColumn('licenses', 'suspension_reason')) {
                $table->string('suspension_reason')->nullable()->after('suspended_at');
            }
        });

        if (! Schema::hasTable('license_payments')) {
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
                // dateTime : évite MySQL 1067 « Invalid default value » (2 TIMESTAMP NOT NULL sous STRICT).
                $table->dateTime('period_start');
                $table->dateTime('period_end');
                $table->string('proof_path')->nullable();
                $table->string('recorded_by_type')->nullable();
                $table->unsignedBigInteger('recorded_by_id')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->timestamps();
                $table->index(['license_id', 'created_at']);
                $table->index(['status', 'paid_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('license_payments');

        Schema::table('licenses', function (Blueprint $table) {
            $drop = array_values(array_filter([
                'reference', 'periodicity', 'auto_renew', 'max_agencies', 'storage_gb',
                'period_started_at', 'suspended_at', 'suspension_reason',
            ], fn (string $c) => Schema::hasColumn('licenses', $c)));

            if ($drop === []) {
                return;
            }

            if (in_array('reference', $drop, true)) {
                try {
                    $table->dropUnique(['reference']);
                } catch (\Throwable) {
                    // index peut déjà être absent
                }
            }

            $table->dropColumn($drop);
        });
    }
};
