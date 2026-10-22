<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Figma « 11 — Équipe, profils & permissions » : invitations, rôles personnalisés, profil, MFA, sessions. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('job_title')->nullable();
            $table->string('employee_number', 32)->nullable();
            $table->json('preferences')->nullable();
            $table->text('mfa_secret')->nullable();
            $table->text('mfa_recovery_codes')->nullable();
            $table->timestamp('mfa_enabled_at')->nullable();
            $table->boolean('mfa_required')->default(false);
            $table->timestamp('password_changed_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
        });

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
        });

        Schema::create('role_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->unique()->constrained('roles')->cascadeOnDelete();
            $table->foreignId('pressing_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->string('description', 250)->nullable();
            $table->string('scope', 16)->default('assigned');
            $table->string('data_level', 24)->default('assigned_agencies');
            $table->json('agency_ids')->nullable();
            $table->string('status', 16)->default('active');
            $table->string('source')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('user_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pressing_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('fullname')->nullable();
            $table->string('role');
            $table->json('agency_ids');
            $table->boolean('require_mfa')->default(false);
            $table->string('token', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['pressing_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_invitations');
        Schema::dropIfExists('role_profiles');
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn(['ip_address', 'user_agent']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['first_name', 'last_name', 'job_title', 'employee_number', 'preferences', 'mfa_secret', 'mfa_recovery_codes', 'mfa_enabled_at', 'mfa_required', 'password_changed_at', 'last_login_at']);
        });
    }
};
