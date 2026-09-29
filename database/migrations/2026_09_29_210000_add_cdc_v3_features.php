<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->char('currency', 3)->default('XAF')->after('country_code');
        });

        Schema::table('pressings', function (Blueprint $table) {
            $table->boolean('qr_labels_enabled')->default(false)->after('workflow_classeur_enabled');
            $table->char('reporting_currency', 3)->nullable()->after('delivery_fee');
        });

        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pressing_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 32)->nullable();
            $table->unsignedTinyInteger('legacy_type_action')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedInteger('default_hours')->nullable();
            $table->boolean('status')->default(true);
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['pressing_id', 'code']);
        });

        Schema::create('agency_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pressing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('article_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->string('pricing_type', 16)->default('piece');
            $table->unsignedBigInteger('amount_minor');
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->timestamps();

            $table->index(['agency_id', 'article_id', 'service_id', 'pricing_type', 'valid_from']);
        });

        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pressing_id')->constrained()->cascadeOnDelete();
            $table->char('source_currency', 3);
            $table->char('target_currency', 3);
            $table->decimal('rate', 18, 8);
            $table->date('rate_date');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['pressing_id', 'source_currency', 'target_currency', 'rate_date'], 'exchange_rates_unique_day');
        });

        Schema::create('delivery_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pressing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agency_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('code', 32)->nullable();
            $table->boolean('status')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('delivery_rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pressing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('livreur_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('delivery_zone_id')->nullable()->constrained('delivery_zones')->nullOnDelete();
            $table->date('round_date');
            $table->string('name')->nullable();
            $table->string('status', 24)->default('open');
            $table->timestamps();
        });

        Schema::table('deposits', function (Blueprint $table) {
            $table->char('currency', 3)->nullable()->after('agency_id');
            $table->string('qr_token', 64)->nullable()->unique()->after('code');
            $table->string('delivery_status', 32)->nullable()->after('etat');
            $table->foreignId('delivery_round_id')->nullable()->after('delivery_status')->constrained('delivery_rounds')->nullOnDelete();
            $table->foreignId('delivery_zone_id')->nullable()->after('delivery_round_id')->constrained('delivery_zones')->nullOnDelete();
            $table->foreignId('livreur_id')->nullable()->after('delivery_zone_id')->constrained('users')->nullOnDelete();
            $table->timestamp('delivered_at')->nullable()->after('livreur_id');
            $table->string('delivery_confirmation_type', 24)->nullable()->after('delivered_at');
            $table->string('delivery_confirmation_value')->nullable()->after('delivery_confirmation_type');
            $table->timestamp('delivery_confirmed_at')->nullable()->after('delivery_confirmation_value');
            $table->string('delivery_address')->nullable()->after('delivery_confirmed_at');
        });

        Schema::table('deposit_units', function (Blueprint $table) {
            $table->foreignId('service_id')->nullable()->after('article_id')->constrained('services')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('deposit_units', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_id');
        });

        Schema::table('deposits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('livreur_id');
            $table->dropConstrainedForeignId('delivery_zone_id');
            $table->dropConstrainedForeignId('delivery_round_id');
            $table->dropColumn([
                'currency',
                'qr_token',
                'delivery_status',
                'delivered_at',
                'delivery_confirmation_type',
                'delivery_confirmation_value',
                'delivery_confirmed_at',
                'delivery_address',
            ]);
        });

        Schema::dropIfExists('delivery_rounds');
        Schema::dropIfExists('delivery_zones');
        Schema::dropIfExists('exchange_rates');
        Schema::dropIfExists('agency_prices');
        Schema::dropIfExists('services');

        Schema::table('pressings', function (Blueprint $table) {
            $table->dropColumn(['qr_labels_enabled', 'reporting_currency']);
        });

        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};
