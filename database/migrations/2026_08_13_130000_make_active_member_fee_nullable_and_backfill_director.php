<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A NULL entry fee now means "not configured yet" (match stays closed for
        // sign-up), which is distinct from an intentional R0 free match.
        Schema::table('matches', function (Blueprint $table) {
            $table->decimal('active_member_fee', 10, 2)->nullable()->default(null)->change();
        });

        // Backfill a named match director from the owning account so existing
        // matches aren't wrongly treated as "no director" once that becomes a
        // requirement for sign-up.
        DB::table('matches')
            ->where(function ($q) {
                $q->whereNull('match_director')->orWhere('match_director', '');
            })
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    if (! $row->created_by) {
                        continue;
                    }

                    $name = DB::table('users')->where('id', $row->created_by)->value('name');
                    if ($name) {
                        DB::table('matches')->where('id', $row->id)->update(['match_director' => $name]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Restore the previous NOT NULL default-0 shape; collapse any NULLs first.
        DB::table('matches')->whereNull('active_member_fee')->update(['active_member_fee' => 0]);

        Schema::table('matches', function (Blueprint $table) {
            $table->decimal('active_member_fee', 10, 2)->default(0)->change();
        });
    }
};
