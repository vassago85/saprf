<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'managed_relationship')) {
            Schema::table('users', function (Blueprint $table) {
                // junior | spouse | parent | sibling | other
                $table->string('managed_relationship', 20)->nullable()->after('is_managed_account');
            });
        }

        // Existing managed accounts pre-date the relationship concept — they
        // were all juniors, so backfill them accordingly.
        DB::table('users')
            ->where('is_managed_account', true)
            ->whereNull('managed_relationship')
            ->update(['managed_relationship' => 'junior']);
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'managed_relationship')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('managed_relationship');
            });
        }
    }
};
