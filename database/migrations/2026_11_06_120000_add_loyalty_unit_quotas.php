<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pressings', function (Blueprint $table) {
            if (! Schema::hasColumn('pressings', 'loyalty_points_for_1')) {
                $table->unsignedInteger('loyalty_points_for_1')->nullable()->after('loyalty_redeem_value');
            }
            if (! Schema::hasColumn('pressings', 'loyalty_points_for_5')) {
                $table->unsignedInteger('loyalty_points_for_5')->nullable()->after('loyalty_points_for_1');
            }
        });

        Schema::table('clients', function (Blueprint $table) {
            if (! Schema::hasColumn('clients', 'loyalty_fcfa_unit')) {
                $table->unsignedTinyInteger('loyalty_fcfa_unit')->nullable()->after('loyalty_points');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pressings', function (Blueprint $table) {
            if (Schema::hasColumn('pressings', 'loyalty_points_for_5')) {
                $table->dropColumn('loyalty_points_for_5');
            }
            if (Schema::hasColumn('pressings', 'loyalty_points_for_1')) {
                $table->dropColumn('loyalty_points_for_1');
            }
        });

        Schema::table('clients', function (Blueprint $table) {
            if (Schema::hasColumn('clients', 'loyalty_fcfa_unit')) {
                $table->dropColumn('loyalty_fcfa_unit');
            }
        });
    }
};
