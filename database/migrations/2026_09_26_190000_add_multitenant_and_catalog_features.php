<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_home')->default(true);
            $table->timestamps();
            $table->unique(['agency_id', 'user_id']);
        });

        Schema::create('promos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pressing_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->unsignedTinyInteger('rate_percent');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('quota')->nullable();
            $table->unsignedInteger('used')->default(0);
            $table->boolean('status')->default(true);
            $table->timestamps();
            $table->unique(['pressing_id', 'code']);
        });

        Schema::create('loyal_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pressing_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedTinyInteger('rate_percent')->default(0);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });

        Schema::create('client_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pressing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('loyal_group_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['client_id', 'loyal_group_id']);
        });

        Schema::create('loyalty_points_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pressing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('deposit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 16);
            $table->integer('points');
            $table->unsignedBigInteger('amount_xaf')->default(0);
            $table->string('reason')->nullable();
            $table->timestamps();
            $table->index(['pressing_id', 'client_id']);
        });

        Schema::create('print_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pressing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agency_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('deposit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('document', 32);
            $table->timestamps();
        });

        Schema::table('deposits', function (Blueprint $table) {
            $table->unsignedBigInteger('collection_fee')->default(0);
            $table->unsignedBigInteger('delivery_fee')->default(0);
            $table->unsignedInteger('points_earned')->default(0);
            $table->unsignedInteger('points_redeemed')->default(0);
            $table->string('promo_code')->nullable();
            $table->unsignedTinyInteger('discount_percent')->default(0);
        });

        Schema::table('pressings', function (Blueprint $table) {
            $table->unsignedBigInteger('collection_fee')->default(0);
            $table->unsignedBigInteger('delivery_fee')->default(0);
            $table->unsignedInteger('loyalty_redeem_threshold')->default(100);
            $table->unsignedInteger('loyalty_redeem_value')->default(100);
        });
    }

    public function down(): void
    {
        Schema::table('pressings', function (Blueprint $table) {
            $table->dropColumn([
                'collection_fee', 'delivery_fee',
                'loyalty_redeem_threshold', 'loyalty_redeem_value',
            ]);
        });

        Schema::table('deposits', function (Blueprint $table) {
            $table->dropColumn([
                'collection_fee', 'delivery_fee', 'points_earned',
                'points_redeemed', 'promo_code', 'discount_percent',
            ]);
        });

        Schema::dropIfExists('print_logs');
        Schema::dropIfExists('loyalty_points_ledger');
        Schema::dropIfExists('client_groups');
        Schema::dropIfExists('loyal_groups');
        Schema::dropIfExists('promos');
        Schema::dropIfExists('agency_user');
    }
};
