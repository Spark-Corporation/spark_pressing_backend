<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('fullname')->nullable()->after('name');
            $table->string('phone_number')->nullable()->after('email');
            $table->string('address')->nullable();
            $table->string('picture')->nullable();
            $table->foreignId('pressing_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('agency_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('status')->default(true);
            $table->softDeletes();

            $table->index(['pressing_id', 'agency_id']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('agency_id');
            $table->dropConstrainedForeignId('pressing_id');
            $table->dropSoftDeletes();
            $table->dropColumn(['fullname', 'phone_number', 'address', 'picture', 'status']);
        });
    }
};
