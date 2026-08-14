<?php

use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Old-site imported matches never settled through this platform, so no
 * platform fee should be booked against them. This migration:
 *
 *   1. Finds every match that had at least one registration whose shooter
 *      account was created by the importer (identified by the stub email
 *      pattern @import.saprf.local).
 *   2. Sets a per-match platform-fee override of R0 on those matches, so
 *      going forward the pricing service returns R0 for any new entry.
 *   3. Zeroes out the historical platform_fee on every paid registration for
 *      those matches, transferring the amount to md_net_amount so the row
 *      arithmetic still balances: fee = saprf + platform + gateway + md_net.
 *
 * This is intentionally scoped to imported matches only — matches created
 * natively on the platform keep their normal platform-fee split. If a match
 * ever needs the override lifted (e.g. because it was re-run natively),
 * exco or a developer can clear it via the match edit form.
 */
return new class extends Migration
{
    public function up(): void
    {
        $importedMatchIds = MatchRegistration::query()
            ->join('users', 'match_registrations.user_id', '=', 'users.id')
            ->where('users.email', 'like', '%@import.saprf.local')
            ->pluck('match_registrations.match_id')
            ->unique()
            ->values();

        if ($importedMatchIds->isEmpty()) {
            return;
        }

        MatchEvent::query()
            ->whereIn('id', $importedMatchIds)
            ->whereNull('platform_fee_type')
            ->whereNull('platform_fee_value')
            ->update([
                'platform_fee_type' => 'fixed',
                'platform_fee_value' => 0,
            ]);

        // Rebalance each historical row: fee_amount stays put, platform_fee
        // drops to R0, md_net_amount picks up the difference. Done via a
        // single UPDATE with an inline expression so we don't have to load
        // every row into PHP just to add two numbers together.
        DB::table('match_registrations')
            ->whereIn('match_id', $importedMatchIds)
            ->where('payment_status', 'paid')
            ->where('registration_status', '!=', 'cancelled')
            ->where('platform_fee', '>', 0)
            ->update([
                'md_net_amount' => DB::raw('md_net_amount + platform_fee'),
                'platform_fee' => 0,
            ]);
    }

    public function down(): void
    {
        // Not reversible — we no longer know the original platform_fee for
        // each row after the rebalance above. Restoring would require the
        // historical import context, which we can't reconstruct here.
    }
};
