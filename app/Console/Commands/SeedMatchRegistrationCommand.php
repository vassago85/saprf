<?php

namespace App\Console\Commands;

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Membership;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\RegistrationPricingService;
use Illuminate\Console\Command;

/**
 * Admin one-off: put a specific member into a match under a specific division
 * without going through the self-service signup + PayFast flow. Used when
 * someone needs to be seeded into a match manually (e.g. paid outside the
 * platform, comp'd entry, late back-fill).
 *
 * The fee breakdown is computed by RegistrationPricingService so the row
 * lines up with everything the reports and payout code expect. Defaults to
 * dry-run — pass --apply to persist.
 */
class SeedMatchRegistrationCommand extends Command
{
    protected $signature = 'registrations:seed
                            {--match= : MatchEvent ID}
                            {--membership= : Membership ID (used to look up the shooter)}
                            {--user= : User ID (alternative to --membership)}
                            {--saprf= : SAPRF membership number (alternative to --membership/--user; resolves via memberships.saprf_number)}
                            {--division= : Division slug (e.g. ladies, open, factory)}
                            {--payment=paid : payment_status to store (paid|waived|pending)}
                            {--category= : Force the fee bracket (active_member|lapsed_member|non_member); bypasses the classifier. Requires --reason.}
                            {--reason= : Free-text explanation stored on the entry as fee_override_reason. Required with --category, optional otherwise.}
                            {--force : Skip the check that the division is offered by the match}
                            {--apply : Persist the change (otherwise dry-run only)}';

    protected $description = 'Manually seed a member into a match under a chosen division';

    public function handle(
        RegistrationPricingService $pricingService,
        AuditLogService $auditLogService,
    ): int {
        $matchId = (int) $this->option('match');
        $membershipId = $this->option('membership') ? (int) $this->option('membership') : null;
        $userIdOpt = $this->option('user') ? (int) $this->option('user') : null;
        $saprfNumber = $this->option('saprf') !== null ? trim((string) $this->option('saprf')) : null;
        if ($saprfNumber === '') {
            $saprfNumber = null;
        }
        $divisionSlug = (string) $this->option('division');
        $paymentStatus = (string) $this->option('payment');
        $forcedCategory = $this->option('category') !== null ? (string) $this->option('category') : null;
        $overrideReason = $this->option('reason') !== null ? trim((string) $this->option('reason')) : null;
        $apply = (bool) $this->option('apply');
        $force = (bool) $this->option('force');

        if ($matchId <= 0 || $divisionSlug === '' || (! $membershipId && ! $userIdOpt && $saprfNumber === null)) {
            $this->error('Required: --match, --division, and one of --membership / --user / --saprf.');

            return self::FAILURE;
        }

        if (! in_array($paymentStatus, ['paid', 'waived', 'pending'], true)) {
            $this->error("--payment must be one of paid|waived|pending (got: {$paymentStatus}).");

            return self::FAILURE;
        }

        if ($forcedCategory !== null && ! in_array($forcedCategory, RegistrationPricingService::CATEGORIES, true)) {
            $this->error(
                "--category must be one of " . implode('|', RegistrationPricingService::CATEGORIES)
                . " (got: {$forcedCategory})."
            );

            return self::FAILURE;
        }

        // Forcing a bracket the member doesn't naturally qualify for is a
        // policy deviation — refuse it silently and require the operator to
        // spell out why, because that string is the only audit signal on the
        // resulting registration row.
        if ($forcedCategory !== null && ($overrideReason === null || $overrideReason === '')) {
            $this->error('--category requires --reason (recorded on the entry as fee_override_reason).');

            return self::FAILURE;
        }

        $match = MatchEvent::find($matchId);
        if (! $match) {
            $this->error("Match not found: {$matchId}");

            return self::FAILURE;
        }

        $division = Division::where('slug', $divisionSlug)->first();
        if (! $division) {
            $this->error("Division not found: {$divisionSlug}");

            return self::FAILURE;
        }

        if (! $force && $match->divisions()->exists() && ! $match->divisions()->where('divisions.id', $division->id)->exists()) {
            $this->error("Division '{$divisionSlug}' is not offered by match #{$matchId}. Use --force to override.");

            return self::FAILURE;
        }

        $user = $this->resolveUser($membershipId, $userIdOpt, $saprfNumber);
        if (! $user) {
            $lookupHint = $saprfNumber !== null
                ? "SAPRF number '{$saprfNumber}' has no matching membership."
                : 'User could not be resolved from the supplied --membership/--user option.';
            $this->error($lookupHint);

            return self::FAILURE;
        }

        if ($existing = $match->userRegistration($user)) {
            $this->error("User #{$user->id} ({$user->name}) already has an active registration (#{$existing->id}, status: {$existing->registration_status}) for this match.");

            return self::FAILURE;
        }

        $matchDate = $match->match_date ?: now();
        $breakdown = $pricingService->calculateBreakdown($match, $user, $matchDate, $divisionSlug, $forcedCategory);

        // Manually seeded entries never go through PayFast, so we must not
        // book the card-rate gateway estimate — otherwise the MD's net for
        // this entry would silently be short by ~3.5% + R2 with no card
        // transaction to explain it. Rebalance md_net to keep the row
        // arithmetically consistent (fee = saprf + platform + surcharge + gateway + md_net).
        $gatewayFee = 0.00;
        $mdNet = round(
            (float) $breakdown['total_fee']
            - (float) $breakdown['saprf_fee']
            - (float) $breakdown['platform_fee']
            - (float) $breakdown['surcharge']
            - $gatewayFee,
            2
        );

        $regStatus = $match->isFull() && $match->waitlist_enabled ? 'waitlisted' : 'confirmed';

        $preview = [
            'match_id' => $match->id,
            'match' => $match->name,
            'user_id' => $user->id,
            'shooter' => $user->name,
            'division' => $division->name.' ('.$division->slug.')',
            'category' => $breakdown['category'].($forcedCategory !== null ? ' (forced via --category)' : ''),
            'fee_amount' => number_format((float) $breakdown['total_fee'], 2),
            'surcharge' => number_format((float) $breakdown['surcharge'], 2),
            'gateway_fee' => number_format($gatewayFee, 2).' (zeroed: outside PayFast)',
            'md_net_amount' => number_format($mdNet, 2),
            'payment_status' => $paymentStatus,
            'registration_status' => $regStatus,
            'override_reason' => $overrideReason ?? '—',
        ];

        $this->info('=== registrations:seed ===');
        foreach ($preview as $k => $v) {
            $this->line(sprintf('%-20s %s', $k, $v));
        }

        if (! $apply) {
            $this->newLine();
            $this->warn('Dry run — re-run with --apply to create the registration.');

            return self::SUCCESS;
        }

        $registration = MatchRegistration::query()->create([
            'match_id' => $match->id,
            'user_id' => $user->id,
            'division_id' => $division->id,
            'shooter_name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'membership_fee_category' => $breakdown['category'],
            'fee_amount' => $breakdown['total_fee'],
            'surcharge_amount' => $breakdown['surcharge'],
            'saprf_fee' => $breakdown['saprf_fee'],
            'platform_fee' => $breakdown['platform_fee'],
            'gateway_fee' => $gatewayFee,
            'md_net_amount' => $mdNet,
            'fee_override_reason' => $overrideReason,
            'payment_status' => $paymentStatus,
            'registration_status' => $regStatus,
            'registered_at' => now(),
        ]);

        $auditLogService->log(
            null,
            'registration.seeded',
            'MatchRegistration',
            $registration->id,
            null,
            [
                'match_id' => $match->id,
                'user_id' => $user->id,
                'division' => $division->slug,
                'payment_status' => $paymentStatus,
                'registration_status' => $regStatus,
                'fee_amount' => (float) $breakdown['total_fee'],
                'forced_category' => $forcedCategory,
                'fee_override_reason' => $overrideReason,
                'reason' => 'manual_seed_via_artisan',
            ],
        );

        $this->newLine();
        $this->info("Registration #{$registration->id} created.");

        return self::SUCCESS;
    }

    /**
     * Resolution precedence: explicit --user wins, then --membership,
     * then --saprf (looked up on memberships.saprf_number, which is unique
     * per shooter). The SAPRF number lookup is loose: leading zeros and
     * whitespace are stripped so operators can paste values from paper
     * entry sheets ("00050", " 50 ") without failing to match.
     */
    private function resolveUser(?int $membershipId, ?int $userId, ?string $saprfNumber): ?User
    {
        if ($userId) {
            return User::find($userId);
        }

        if ($membershipId) {
            return Membership::find($membershipId)?->user;
        }

        if ($saprfNumber !== null) {
            $normalised = ltrim(trim($saprfNumber), '0');
            if ($normalised === '') {
                // "0"/"00" — legal SAPRF numbers are always positive, so
                // treat as a lookup miss rather than fall through and
                // load every membership with saprf_number = ''.
                return null;
            }

            return Membership::where('saprf_number', $normalised)->first()?->user
                ?? Membership::where('saprf_number', $saprfNumber)->first()?->user;
        }

        return null;
    }
}
