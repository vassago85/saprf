<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Best-effort backfill: rows with a valid 13-digit SA ID number are
     * treated as SA citizens resident in ZA. Rows without a valid SA ID are
     * left null and will show as MANUAL / unset in the selection admin UI.
     * Loaded row-by-row so it works on both MySQL and sqlite (no regexp fn).
     */
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'sa_citizen') || ! Schema::hasColumn('users', 'sa_id_number')) {
            return;
        }

        DB::table('users')
            ->whereNotNull('sa_id_number')
            ->whereNull('sa_citizen')
            ->orderBy('id')
            ->select(['id', 'sa_id_number'])
            ->chunkById(500, function ($rows) {
                $ids = [];
                foreach ($rows as $row) {
                    if (preg_match('/^[0-9]{13}$/', (string) $row->sa_id_number)) {
                        $ids[] = $row->id;
                    }
                }
                if ($ids) {
                    DB::table('users')->whereIn('id', $ids)->update([
                        'sa_citizen' => true,
                        'country_of_residence' => 'ZA',
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Non-reversible best-effort backfill; leaving values in place is safe.
    }
};
