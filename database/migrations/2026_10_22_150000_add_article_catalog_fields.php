<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Écrans Figma « 07 — Articles & tarifs » : catégories, mode de facturation (pièce / kilo / mixte),
 * grilles au kilo, règles de prix, délais, compatibilités et disponibilité par agence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pressing_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('icon', 32)->nullable();
            $table->boolean('shared')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['pressing_id', 'name']);
        });

        Schema::table('articles', function (Blueprint $table) {
            $table->string('code', 32)->nullable();
            $table->foreignId('category_id')->nullable()->constrained('article_categories')->nullOnDelete();
            $table->string('billing_mode', 8)->default('piece');
            $table->string('icon', 32)->nullable();
            $table->boolean('allow_discount')->default(true);
            $table->unsignedInteger('round_to')->default(0);
            $table->boolean('price_editable')->default(false);
            $table->unsignedSmallInteger('classic_hours')->nullable();
            $table->unsignedSmallInteger('express_hours')->nullable();
            $table->unsignedSmallInteger('repass_hours')->nullable();
            $table->string('priority', 16)->default('standard');
            $table->json('accepted_defects')->nullable();
            $table->json('compatible_renders')->nullable();
            $table->json('services')->nullable();
            $table->json('kilo_tiers')->nullable();
            $table->timestamp('price_updated_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::create('article_agency_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->boolean('available')->default(true);
            $table->smallInteger('price_adjust_percent')->default(0);
            $table->string('note')->nullable();
            $table->timestamps();
            $table->unique(['article_id', 'agency_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_agency_settings');

        Schema::table('articles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
            $table->dropConstrainedForeignId('updated_by');
            $table->dropColumn([
                'code', 'billing_mode', 'icon', 'allow_discount', 'round_to', 'price_editable',
                'classic_hours', 'express_hours', 'repass_hours', 'priority', 'accepted_defects',
                'compatible_renders', 'services', 'kilo_tiers', 'price_updated_at',
            ]);
        });

        Schema::dropIfExists('article_categories');
    }
};
