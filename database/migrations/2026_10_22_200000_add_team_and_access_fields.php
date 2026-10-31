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
            foreach ([
                'first_name' => fn (Blueprint $t) => $t->string('first_name')->nullable(),
                'last_name' => fn (Blueprint $t) => $t->string('last_name')->nullable(),
                'job_title' => fn (Blueprint $t) => $t->string('job_title')->nullable(),
                'employee_number' => fn (Blueprint $t) => $t->string('employee_number', 32)->nullable(),
                'preferences' => fn (Blueprint $t) => $t->json('preferences')->nullable(),
                'mfa_secret' => fn (Blueprint $t) => $t->text('mfa_secret')->nullable(),
                'mfa_recovery_codes' => fn (Blueprint $t) => $t->text('mfa_recovery_codes')->nullable(),
                'mfa_enabled_at' => fn (Blueprint $t) => $t->timestamp('mfa_enabled_at')->nullable(),
                'mfa_required' => fn (Blueprint $t) => $t->boolean('mfa_required')->default(false),
                'password_changed_at' => fn (Blueprint $t) => $t->timestamp('password_changed_at')->nullable(),
                'last_login_at' => fn (Blueprint $t) => $t->timestamp('last_login_at')->nullable(),
            ] as $column => $add) {
                if (! Schema::hasColumn('users', $column)) {
                    $add($table);
                }
            }
        });

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            if (! Schema::hasColumn('personal_access_tokens', 'ip_address')) {
                $table->string('ip_address', 45)->nullable();
            }
            if (! Schema::hasColumn('personal_access_tokens', 'user_agent')) {
                $table->string('user_agent')->nullable();
            }
        });

        if (! Schema::hasTable('role_profiles')) {
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
        }

        if (! Schema::hasTable('user_invitations')) {
            Schema::create('user_invitations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('pressing_id')->constrained()->cascadeOnDelete();
                $table->string('email');
                $table->string('fullname')->nullable();
                $table->string('role');
                $table->json('agency_ids');
                $table->boolean('require_mfa')->default(false);
                $table->string('token', 64)->unique();
                $table->dateTime('expires_at');
                $table->timestamp('accepted_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['pressing_id', 'email']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_invitations');
        Schema::dropIfExists('role_profiles');

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $drop = array_values(array_filter(['ip_address', 'user_agent'], fn ($c) => Schema::hasColumn('personal_access_tokens', $c)));
            if ($drop) {
                $table->dropColumn($drop);
            }
        });

        Schema::table('users', function (Blueprint $table) {
            $cols = ['first_name', 'last_name', 'job_title', 'employee_number', 'preferences', 'mfa_secret', 'mfa_recovery_codes', 'mfa_enabled_at', 'mfa_required', 'password_changed_at', 'last_login_at'];
            $drop = array_values(array_filter($cols, fn ($c) => Schema::hasColumn('users', $c)));
            if ($drop) {
                $table->dropColumn($drop);
            }
        });
    }
};
