<?php

use App\Models\MatchRegistration;
use App\Services\RegistrationPricingService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-shot repair for Impact-touched unpaid entries.
 *
 * Historical versions of ImpactLiveImportService rewrote still-unpaid
 * registrations to `md_net_amount = -(saprf_fee + platform_fee)` so the
 * MD payout "charged back" the SAPRF levy. That is wrong: the shooter
 * still owes the full fee and will be forced to pay it before entering
 * another match, so the row should keep its original priced breakdown
 * with a positive MD net. Walk-in entries (`registration_source = 'walk_in'`)
 * are untouched — those really did skip the gateway and are paid.
 */
return new class extends Migration
{
    public function up(): void
    {
        $pricing = app(RegistrationPricingService::class);

        MatchRegistration::query()
            ->with(['match', 'user', 'division'])
            ->where('registration_status', '!=', 'cancelled')
            ->whereNotIn('payment_status', ['paid', 'waived'])
            ->where(function ($q) {
                $q->whereNull('registration_source')
                    ->orWhere('registration_source', '!=', 'walk_in');
            })
            ->where('fee_amount', '>', 0)
            ->where('md_net_amount', '<', 0)
            ->chunkById(200, function ($rows) use ($pricing): void {
                foreach ($rows as $reg) {
                    if (! $reg->match) {
                        continue;
                    }

                    $breakdown = $pricing->calculateBreakdown(
                        $reg->match,
                        $reg->user,
                        $reg->match->match_date ?? now(),
                        $reg->division?->slug,
                        $reg->membership_fee_category,
                        $reg->registered_at,
                    );

                    DB::table('match_registrations')
                        ->where('id', $reg->id)
                        ->update([
                            'saprf_fee' => $breakdown['saprf_fee'],
                            'platform_fee' => $breakdown['platform_fee'],
                            'surcharge_amount' => $breakdown['surcharge'],
                            'gateway_fee' => $breakdown['gateway_fee'],
                            'md_net_amount' => $breakdown['md_net'],
                            'updated_at' => now(),
                        ]);
                }
            });
    }

    public function down(): void
    {
        // One-shot data fix — no reversal.
    }
};
