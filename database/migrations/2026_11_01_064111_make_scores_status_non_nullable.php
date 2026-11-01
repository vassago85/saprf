<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Once `php artisan scores:backfill-pending-status --apply` has filled
 * every NULL status with 'pending', lock the column down so a direct
 * SQL insert or a future importer regression can't reintroduce the
 * ambiguous NULL state. The column defaulted to 'pending' in the
 * create migration, but was still nullable — any explicit NULL from a
 * seeder or raw INSERT would stick.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Last-chance guard: refuse to migrate if any NULLs survived the
        // backfill command. The operator will see this message in the
        // deploy log instead of silently losing rows to the ->default()
        // when MariaDB rewrites the column type.
        $remaining = DB::table('scores')->whereNull('status')->count();
        if ($remaining > 0) {
            throw new RuntimeException(
                "Cannot set scores.status NOT NULL: {$remaining} row(s) still have status IS NULL. "
                .'Run `php artisan scores:backfill-pending-status --apply` first.'
            );
        }

        Schema::table('scores', function (Blueprint $table): void {
            // ->change() + ->default() + non-nullable requires doctrine/dbal
            // on older Laravel versions. Laravel 12's native Blueprint
            // handles this without the extra dependency.
            $table->string('status')->default('pending')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('scores', function (Blueprint $table): void {
            $table->string('status')->default('pending')->nullable()->change();
        });
    }
};
