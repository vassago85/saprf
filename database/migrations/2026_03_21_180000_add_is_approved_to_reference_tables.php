<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $referenceTables = ['firearm_makes', 'firearm_models', 'firearm_calibres', 'optic_makes', 'optic_models'];

        foreach ($referenceTables as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->boolean('is_approved')->default(false)->after('user_submitted');
            });

            DB::table($table)->where('user_submitted', false)->update(['is_approved' => true]);
        }

        Schema::table('venues', function (Blueprint $table) {
            $table->boolean('is_approved')->default(false)->after('is_active');
            $table->foreignId('submitted_by')->nullable()->after('is_approved')->constrained('users')->nullOnDelete();
        });

        DB::table('venues')->update(['is_approved' => true]);
    }

    public function down(): void
    {
        $referenceTables = ['firearm_makes', 'firearm_models', 'firearm_calibres', 'optic_makes', 'optic_models'];

        foreach ($referenceTables as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('is_approved');
            });
        }

        Schema::table('venues', function (Blueprint $table) {
            $table->dropForeign(['submitted_by']);
            $table->dropColumn(['is_approved', 'submitted_by']);
        });
    }
};
