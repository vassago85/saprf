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
                            {--division= : Division slug (e.g. ladies, open, factory)}
                            {--payment=paid : payment_status to store (paid|waived|pending)}
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
        $divisionSlug = (string) $this->option('division');
        $paymentStatus = (string) $this->option('payment');
        $apply = (bool) $this->option('apply');
        $force = (bool) $this->option('force');

        if ($matchId <= 0 || $divisionSlug === '' || (! $membershipId && ! $userIdOpt)) {
            $this->error('Required: --match, --division, and one of --membership or --user.');

            return self::FAILURE;
        }

        if (! in_array($paymentStatus, ['paid', 'waived', 'pending'], true)) {
            $this->error("--payment must be one of paid|waived|pending (got: {$paymentStatus}).");

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

        $user = $this->resolveUser($membershipId, $userIdOpt);
        if (! $user) {
            $this->error('User could not be resolved from the supplied --membership/--user option.');

            return self::FAILURE;
        }

        if ($existing = $match->userRegistration($user)) {
            $this->error("User #{$user->id} ({$user->name}) already has an active registration (#{$existing->id}, status: {$existing->registration_status}) for this match.");

            return self::FAILURE;
        }

        $matchDate = $match->match_date ?: now();
        $breakdown = $pricingService->calculateBreakdown($match, $user, $matchDate, $divisionSlug);

        $regStatus = $match->isFull() && $match->waitlist_enabled ? 'waitlisted' : 'confirmed';

        $preview = [
            'match_id' => $match->id,
            'match' => $match->name,
            'user_id' => $user->id,
            'shooter' => $user->name,
            'division' => $division->name.' ('.$division->slug.')',
            'category' => $breakdown['category'],
            'fee_amount' => number_format((float) $breakdown['total_fee'], 2),
            'payment_status' => $paymentStatus,
            'registration_status' => $regStatus,
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
            'gateway_fee' => $breakdown['gateway_fee'],
            'md_net_amount' => $breakdown['md_net'],
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
                'reason' => 'manual_seed_via_artisan',
            ],
        );

        $this->newLine();
        $this->info("Registration #{$registration->id} created.");

        return self::SUCCESS;
    }

    private function resolveUser(?int $membershipId, ?int $userId): ?User
    {
        if ($userId) {
            return User::find($userId);
        }

        $membership = Membership::find($membershipId);

        return $membership?->user;
    }
}
