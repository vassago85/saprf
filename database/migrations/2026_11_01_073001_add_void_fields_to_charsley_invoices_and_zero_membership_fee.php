<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a sent Charsley invoice be voided (e.g. credited in Invoice Ninja
 * after it went out at the wrong amount), freeing the month to be
 * re-invoiced. Also drops the unique index on `period` so a voided row
 * and a live row can co-exist for the same month — uniqueness is
 * re-enforced in the application layer against `voided_at IS NULL`.
 *
 * Second concern: zero out `membership_platform_fee_pct`. SAPRF/Charsley
 * does not take a cut on memberships, so the setting (used by both
 * FinancialService and the Charsley invoice) must default to 0 and the
 * stored setting is reset here. If an operator wants a non-zero rate
 * later they can set it from Site Settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charsley_invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('charsley_invoices', 'voided_at')) {
                $table->timestamp('voided_at')->nullable()->after('paid_at');
                $table->index('voided_at');
            }
            if (! Schema::hasColumn('charsley_invoices', 'voided_reason')) {
                $table->text('voided_reason')->nullable()->after('voided_at');
            }
        });

        // Drop the UNIQUE on period so a voided row and a replacement can
        // coexist. SQLite in tests needs the index dropped by name only; MySQL
        // / MariaDB keeps that same name because the original migration used
        // `->unique()`.
        try {
            Schema::table('charsley_invoices', function (Blueprint $table) {
                $table->dropUnique('charsley_invoices_period_unique');
            });
        } catch (\Throwable) {
            // Already gone (fresh install or earlier rerun), carry on.
        }

        // Keep a lookup index on period so the active-period query is fast.
        try {
            Schema::table('charsley_invoices', function (Blueprint $table) {
                $table->index('period', 'charsley_invoices_period_index');
            });
        } catch (\Throwable) {
            // Index already exists.
        }

        // Charsley takes 0% on memberships. Update the stored setting so the
        // finance dashboard and the Charsley invoice both reflect it on the
        // next request. Create the row if it doesn't exist so admins have
        // something to edit from Site Settings.
        if (Schema::hasTable('settings')) {
            Setting::query()->updateOrCreate(
                ['key' => 'membership_platform_fee_pct'],
                [
                    'value' => '0',
                    'description' => 'Platform fee % on membership and other non-match transactions. 0 = platform takes nothing.',
                ],
            );
        }
    }

    public function down(): void
    {
        Schema::table('charsley_invoices', function (Blueprint $table) {
            if (Schema::hasColumn('charsley_invoices', 'voided_reason')) {
                $table->dropColumn('voided_reason');
            }
            if (Schema::hasColumn('charsley_invoices', 'voided_at')) {
                $table->dropIndex(['voided_at']);
                $table->dropColumn('voided_at');
            }
        });

        try {
            Schema::table('charsley_invoices', function (Blueprint $table) {
                $table->dropIndex('charsley_invoices_period_index');
            });
        } catch (\Throwable) {
            // Index not present.
        }

        try {
            Schema::table('charsley_invoices', function (Blueprint $table) {
                $table->unique('period', 'charsley_invoices_period_unique');
            });
        } catch (\Throwable) {
            // Can't restore uniqueness because duplicate rows now exist. The
            // app-level filter on voided_at is what prevents duplicates going
            // forward, so leave the UNIQUE off and move on.
        }

        if (Schema::hasTable('settings')) {
            DB::table('settings')
                ->where('key', 'membership_platform_fee_pct')
                ->update(['value' => '2.5']);
        }
    }
};
