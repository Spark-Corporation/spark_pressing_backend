<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Écrans Figma « 05 — Retraits » : notification client, suivi des dépôts anciens
 * (frais de garde, escalade) et traitement du retrait (état, réceptionnaire, dérogation).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deposits', function (Blueprint $table) {
            $table->string('notification_channel', 16)->nullable();
            $table->string('notification_status', 16)->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('notification_read_at')->nullable();
            $table->unsignedBigInteger('storage_fee')->default(0);
            $table->timestamp('escalated_at')->nullable();
            $table->timestamp('aged_handled_at')->nullable();
            $table->string('retrieve_condition', 16)->nullable();
            $table->text('retrieve_remarks')->nullable();
            $table->string('receiver_type', 16)->nullable();
            $table->boolean('identity_confirmed')->default(false);
            $table->string('retrieve_override_reason')->nullable();
        });

        Schema::table('deposit_units', function (Blueprint $table) {
            $table->string('verification', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('deposit_units', function (Blueprint $table) {
            $table->dropColumn('verification');
        });

        Schema::table('deposits', function (Blueprint $table) {
            $table->dropColumn([
                'notification_channel', 'notification_status', 'notified_at', 'notification_read_at',
                'storage_fee', 'escalated_at', 'aged_handled_at', 'retrieve_condition', 'retrieve_remarks',
                'receiver_type', 'identity_confirmed', 'retrieve_override_reason',
            ]);
        });
    }
};
