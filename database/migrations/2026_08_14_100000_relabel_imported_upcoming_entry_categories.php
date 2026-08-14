<?php

use App\Models\MatchRegistration;
use App\Models\Membership;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Old-site upcoming entries were classified as lapsed_member when the
     * shooter's platform membership did not cover the future match date.
     * Those people were full members on the sheet — retag them as
     * active_member. Match-day score validation still checks that they
     * renewed before the match, so a stale membership won't count toward
     * the season log regardless.
     *
     * Also cleans up any imported_member rows created by an earlier draft
     * of this rework, folding them into active_member.
     */
    public function up(): void
    {
        $path = database_path('data/upcoming_entries_2026.json');
        if (! is_file($path)) {
            return;
        }

        $dataset = json_decode((string) file_get_contents($path), true);
        $fullSaprfNumbers = collect($dataset['entrants'] ?? [])
            ->where('membership_type', 'full')
            ->pluck('saprf_number')
            ->map(fn ($number) => (string) $number)
            ->unique()
            ->values()
            ->all();

        if ($fullSaprfNumbers !== []) {
            $userIds = Membership::query()
                ->whereIn('saprf_number', $fullSaprfNumbers)
                ->pluck('user_id');

            MatchRegistration::query()
                ->whereIn('user_id', $userIds)
                ->where('membership_fee_category', 'lapsed_member')
                ->where('payment_status', 'paid')
                ->where('registration_status', 'confirmed')
                ->update(['membership_fee_category' => 'active_member']);
        }

        // Any imported_member rows created by the earlier draft of this
        // rework should simply be active_member — the sheet already vouched
        // for their membership at signup.
        MatchRegistration::query()
            ->where('membership_fee_category', 'imported_member')
            ->update(['membership_fee_category' => 'active_member']);
    }

    public function down(): void
    {
        // Not reversible — the original per-row category (active vs lapsed)
        // was inferred at import time from the platform membership window,
        // which we can no longer reconstruct after promoting rows.
    }
};
