<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            if (! Schema::hasColumn('agencies', 'currency')) {
                $table->char('currency', 3)->default('XAF')->after('country_code');
            }
        });

        Schema::table('pressings', function (Blueprint $table) {
            if (! Schema::hasColumn('pressings', 'qr_labels_enabled')) {
                $table->boolean('qr_labels_enabled')->default(false)->after('workflow_classeur_enabled');
            }
            if (! Schema::hasColumn('pressings', 'reporting_currency')) {
                $table->char('reporting_currency', 3)->nullable()->after('delivery_fee');
            }
        });

        if (! Schema::hasTable('services')) {
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
        }

        // Peut exister sans index après un échec MySQL (nom d'index trop long).
        Schema::dropIfExists('agency_prices');
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

            $table->index(
                ['agency_id', 'article_id', 'service_id', 'pricing_type', 'valid_from'],
                'agency_prices_lookup_idx'
            );
        });

        if (! Schema::hasTable('exchange_rates')) {
            Schema::create('exchange_rates', function (Blueprint $table) {
                $table->id();
                $table->foreignId('pressing_id')->constrained()->cascadeOnDelete();
                $table->char('source_currency', 3);
                $table->char('target_currency', 3);
                $table->decimal('rate', 18, 8);
                $table->date('rate_date');
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(
                    ['pressing_id', 'source_currency', 'target_currency', 'rate_date'],
                    'exchange_rates_unique_day'
                );
            });
        }

        if (! Schema::hasTable('delivery_zones')) {
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
        }

        if (! Schema::hasTable('delivery_rounds')) {
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
        }

        Schema::table('deposits', function (Blueprint $table) {
            if (! Schema::hasColumn('deposits', 'currency')) {
                $table->char('currency', 3)->nullable()->after('agency_id');
            }
            if (! Schema::hasColumn('deposits', 'qr_token')) {
                $table->string('qr_token', 64)->nullable()->unique()->after('code');
            }
            if (! Schema::hasColumn('deposits', 'delivery_status')) {
                $table->string('delivery_status', 32)->nullable()->after('etat');
            }
            if (! Schema::hasColumn('deposits', 'delivery_round_id')) {
                $table->foreignId('delivery_round_id')->nullable()->after('delivery_status')->constrained('delivery_rounds')->nullOnDelete();
            }
            if (! Schema::hasColumn('deposits', 'delivery_zone_id')) {
                $table->foreignId('delivery_zone_id')->nullable()->after('delivery_round_id')->constrained('delivery_zones')->nullOnDelete();
            }
            if (! Schema::hasColumn('deposits', 'livreur_id')) {
                $table->foreignId('livreur_id')->nullable()->after('delivery_zone_id')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('deposits', 'delivered_at')) {
                $table->timestamp('delivered_at')->nullable()->after('livreur_id');
            }
            if (! Schema::hasColumn('deposits', 'delivery_confirmation_type')) {
                $table->string('delivery_confirmation_type', 24)->nullable()->after('delivered_at');
            }
            if (! Schema::hasColumn('deposits', 'delivery_confirmation_value')) {
                $table->string('delivery_confirmation_value')->nullable()->after('delivery_confirmation_type');
            }
            if (! Schema::hasColumn('deposits', 'delivery_confirmed_at')) {
                $table->timestamp('delivery_confirmed_at')->nullable()->after('delivery_confirmation_value');
            }
            if (! Schema::hasColumn('deposits', 'delivery_address')) {
                $table->string('delivery_address')->nullable()->after('delivery_confirmed_at');
            }
        });

        Schema::table('deposit_units', function (Blueprint $table) {
            if (! Schema::hasColumn('deposit_units', 'service_id')) {
                $table->foreignId('service_id')->nullable()->after('article_id')->constrained('services')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('deposit_units', function (Blueprint $table) {
            if (Schema::hasColumn('deposit_units', 'service_id')) {
                $table->dropConstrainedForeignId('service_id');
            }
        });

        Schema::table('deposits', function (Blueprint $table) {
            if (Schema::hasColumn('deposits', 'livreur_id')) {
                $table->dropConstrainedForeignId('livreur_id');
            }
            if (Schema::hasColumn('deposits', 'delivery_zone_id')) {
                $table->dropConstrainedForeignId('delivery_zone_id');
            }
            if (Schema::hasColumn('deposits', 'delivery_round_id')) {
                $table->dropConstrainedForeignId('delivery_round_id');
            }
            $cols = [
                'currency',
                'qr_token',
                'delivery_status',
                'delivered_at',
                'delivery_confirmation_type',
                'delivery_confirmation_value',
                'delivery_confirmed_at',
                'delivery_address',
            ];
            $drop = array_values(array_filter($cols, fn (string $c) => Schema::hasColumn('deposits', $c)));
            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });

        Schema::dropIfExists('delivery_rounds');
        Schema::dropIfExists('delivery_zones');
        Schema::dropIfExists('exchange_rates');
        Schema::dropIfExists('agency_prices');
        Schema::dropIfExists('services');

        Schema::table('pressings', function (Blueprint $table) {
            $drop = array_values(array_filter(
                ['qr_labels_enabled', 'reporting_currency'],
                fn (string $c) => Schema::hasColumn('pressings', $c)
            ));
            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });

        Schema::table('agencies', function (Blueprint $table) {
            if (Schema::hasColumn('agencies', 'currency')) {
                $table->dropColumn('currency');
            }
        });
    }
};
