<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Figma « 09 — Paramètres » : campagnes promotionnelles, zones de collecte/livraison, journaux de notifications. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promos', function (Blueprint $table) {
            $table->string('name')->nullable()->after('code');
            $table->string('type', 16)->default('percent')->after('name');
            $table->unsignedInteger('amount')->nullable()->after('rate_percent');
            $table->unsignedInteger('per_client_limit')->nullable()->after('quota');
            $table->json('agency_ids')->nullable();
            $table->unsignedInteger('min_amount')->default(0);
            $table->unsignedInteger('max_discount')->nullable();
            $table->boolean('stack_with_loyalty')->default(false);
            $table->timestamp('published_at')->nullable();
        });

        Schema::table('delivery_zones', function (Blueprint $table) {
            $table->json('quarters')->nullable();
            $table->decimal('radius_km', 6, 2)->nullable();
            $table->string('departure_address')->nullable();
            $table->unsignedInteger('collection_fee')->nullable();
            $table->unsignedInteger('delivery_fee')->nullable();
            $table->timestamp('verified_at')->nullable();
        });

        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pressing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agency_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('deposit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 32);
            $table->string('channel', 16);
            $table->string('recipient')->nullable();
            $table->text('message');
            $table->string('status', 16)->default('sent');
            $table->string('error')->nullable();
            $table->boolean('is_test')->default(false);
            $table->timestamps();
            $table->index(['pressing_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
        Schema::table('delivery_zones', function (Blueprint $table) {
            $table->dropColumn(['quarters', 'radius_km', 'departure_address', 'collection_fee', 'delivery_fee', 'verified_at']);
        });
        Schema::table('promos', function (Blueprint $table) {
            $table->dropColumn(['name', 'type', 'amount', 'per_client_limit', 'agency_ids', 'min_amount', 'max_discount', 'stack_with_loyalty', 'published_at']);
        });
    }
};
