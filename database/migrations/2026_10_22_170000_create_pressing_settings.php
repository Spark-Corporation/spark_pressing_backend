<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Écrans Figma « 09 — Paramètres » : réglages par catégorie, versions publiées, postes de caisse. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pressing_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pressing_id')->constrained()->cascadeOnDelete();
            $table->string('category', 32);
            $table->json('values')->nullable();
            $table->json('draft')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['pressing_id', 'category']);
        });

        Schema::create('pressing_setting_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pressing_id')->constrained()->cascadeOnDelete();
            $table->string('category', 32);
            $table->json('values');
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['pressing_id', 'category', 'id']);
        });

        Schema::create('pos_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pressing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('type', 16)->default('pos');
            $table->string('label')->nullable();
            $table->foreignId('responsible_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16)->default('pending');
            $table->boolean('offline_enabled')->default(false);
            $table->timestamp('authorized_at')->nullable();
            $table->foreignId('authorized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->unique(['pressing_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_devices');
        Schema::dropIfExists('pressing_setting_versions');
        Schema::dropIfExists('pressing_settings');
    }
};
