<?php

namespace App\Console\Commands;

use App\Models\Club;
use App\Models\Membership;
use App\Models\Province;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Create user + membership records for the two 2022 IPRF Factory-class
 * shooters who were not carried over in the legacy member import —
 * Perry De Gouveia and Rob Ramsden.
 *
 * Both were on the 2022 Team South Africa roster (IPRF Centrefire World
 * Championship, Bitche, France) and were awarded Protea Colours per the
 * SAPRF Chairperson's On Target Africa article (Issue 38, June/July 2022).
 * The 2022 seeder (`saprf:seed-national-team-2022`) logs them as
 * UNRESOLVED on every run because no user account exists for them. This
 * command creates those accounts from the member details Exco forwarded
 * (lifted from the previous platform's membership CSV).
 *
 * Dedup order (first hit wins — safe to rerun):
 *   1. `users.email` exact (case-insensitive).
 *   2. `users.sa_id_number` exact (where supplied).
 *   3. Normalised `users.name` exact (lowercase, whitespace-collapsed).
 *
 * If a match hits, we log SKIP and leave the row alone — the live record
 * takes precedence over the legacy snapshot. Otherwise we create:
 *
 *   - Full `User` row with name, email, phone, DOB, gender, ethnicity,
 *     SA ID (Rob only — Perry's legacy ID was a UUID, so sa_id_number
 *     stays null), province, address, and a random unknowable password.
 *     The account is `is_active=true` + `email_verified_at=null` so these
 *     two can claim it via Forgot Password later. `member` role attached.
 *
 *   - Paired `Membership` row with the next available sequential SAPRF
 *     number (`Membership::nextSaprfNumber()`), membership_type=full,
 *     payment_status=paid, and the expiry date from the legacy snapshot
 *     (both are expired — the computed membershipStatus() returns Expired
 *     accordingly, matching reality).
 *
 * Primary shooting club is attached when a matching Club row exists.
 * Missing clubs log a warning but do not block account creation — Exco
 * can attach the club via the admin UI once it's seeded.
 *
 * Idempotent: re-running finds the already-created accounts via the
 * dedup rules and logs SKIP. Safe to chain after the 2022 seeder.
 */
class CreateMissing2022ShootersCommand extends Command
{
    protected $signature = 'saprf:create-missing-2022-shooters
        {--dry-run : Report what would change without writing}';

    protected $description = 'Create user + membership records for Perry De Gouveia and Rob Ramsden (2022 IPRF Factory, missing from the legacy import)';

    /**
     * Legacy-platform member data forwarded by Exco in Oct 2026. Each entry
     * becomes one User + one Membership row. Field names mirror the User
     * fillable list so the loop below can mass-assign cleanly.
     */
    private const SHOOTERS = [
        [
            'name' => 'Rob Ramsden',
            'email' => 'rob@ramsden.co.za',
            'phone' => '0828813199',
            'sa_id_number' => '7303235253086',
            'date_of_birth' => '1973-03-23',
            'gender' => 'male',
            'ethnicity' => 'white',
            'province' => 'Western Cape',
            'club' => 'False Bay Sport Shooting Club',
            'address_line_1' => 'Ohio Farm Cottage, Chapmans Peak Drive',
            'city' => 'Cape Town',
            'postal_code' => '7979',
            'membership_expiry' => '2023-03-08',
        ],
        [
            'name' => 'Perry De Gouveia',
            'email' => 'perrydegouveia@gmail.com',
            'phone' => '0795019292',
            // Perry's legacy ID was a UUID (8AB805F8-E30E-4588-BA6A-
            // C58ABEBFCC2A), not a real SA ID — leave null so a future
            // real-ID capture via My Profile isn't blocked by a bogus
            // placeholder.
            'sa_id_number' => null,
            'date_of_birth' => '1992-10-23',
            'gender' => 'male',
            'ethnicity' => 'white',
            'province' => 'Gauteng',
            'club' => 'Krokodilspruit Skietklub',
            'address_line_1' => '17 Study Road',
            'address_line_2' => 'Glenhazel',
            'city' => 'Johannesburg',
            'postal_code' => '2192',
            'membership_expiry' => '2023-05-02',
        ],
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->info('=== saprf:create-missing-2022-shooters ===');
        $this->line('Shooters:   '.count(self::SHOOTERS));
        $this->line('Dry run:    '.($dryRun ? 'YES' : 'no'));
        $this->newLine();

        $provincesByName = Province::all()->keyBy(fn (Province $p) => strtolower($p->name));
        $clubsByName = Club::all()->keyBy(fn (Club $c) => strtolower($c->name));

        $report = [
            'created' => 0,
            'skipped' => 0,
            'club_missing' => [],
        ];

        $tx = $dryRun ? null : DB::beginTransaction();

        try {
            foreach (self::SHOOTERS as $data) {
                $existing = $this->findExistingUser($data);
                if ($existing !== null) {
                    $report['skipped']++;
                    $saprf = $existing->membership?->saprf_number ?? '—';
                    $this->line("  SKIP        {$data['name']} — already exists (id={$existing->id}, SAPRF={$saprf})");

                    continue;
                }

                $province = $provincesByName->get(strtolower($data['province'])) ?? null;
                if ($province === null) {
                    $this->warn("  WARNING     {$data['name']} — province '{$data['province']}' not found; leaving province_id null");
                }

                $club = $clubsByName->get(strtolower($data['club'])) ?? null;
                if ($club === null) {
                    $report['club_missing'][] = [
                        'shooter' => $data['name'],
                        'club' => $data['club'],
                    ];
                    $this->warn("  WARNING     {$data['name']} — club '{$data['club']}' not found; leaving club_id null (attach via admin UI)");
                }

                $this->line(sprintf(
                    '  CREATE      %-20s  email=%-30s  province=%-15s',
                    $data['name'],
                    $data['email'],
                    $data['province'],
                ));

                if ($dryRun) {
                    $report['created']++;

                    continue;
                }

                $user = User::create([
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'phone' => $data['phone'],
                    'sa_id_number' => $data['sa_id_number'],
                    'date_of_birth' => $data['date_of_birth'],
                    'gender' => $data['gender'],
                    'ethnicity' => $data['ethnicity'],
                    'province_id' => $province?->id,
                    'club_id' => $club?->id,
                    'address_line_1' => $data['address_line_1'],
                    'address_line_2' => $data['address_line_2'] ?? null,
                    'city' => $data['city'],
                    'postal_code' => $data['postal_code'],
                    'country_of_residence' => 'ZA',
                    'sa_citizen' => true,
                    // Random unknowable password — the account is
                    // unclaimed. If/when the shooter wants to log in,
                    // Forgot Password against their real email will let
                    // them set a password of their own.
                    'password' => Hash::make(Str::random(64)),
                    'is_active' => true,
                    'is_managed_account' => false,
                    'must_change_password' => false,
                    'email_verified_at' => null,
                ]);
                $user->assignRole('member');

                // membership_type=full + payment_status=paid + expiry in
                // the past means membershipStatus() computes to Expired
                // (via the Lapsed → Expired grace-cutoff path), which is
                // the accurate historical state for both of these.
                Membership::create([
                    'user_id' => $user->id,
                    'saprf_number' => Membership::nextSaprfNumber(),
                    'membership_type' => 'full',
                    'status' => 'active',
                    'payment_status' => 'paid',
                    'start_date' => null,
                    'expiry_date' => Carbon::parse($data['membership_expiry']),
                ]);

                $report['created']++;
            }

            if (! $dryRun) {
                DB::commit();
            }
        } catch (\Throwable $e) {
            if (! $dryRun) {
                DB::rollBack();
            }
            $this->error('Create aborted: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('--- Summary ---');
        $this->line('Created:         '.$report['created']);
        $this->line('Skipped:         '.$report['skipped']);
        $this->line('Clubs missing:   '.count($report['club_missing']));

        if ($report['club_missing']) {
            $this->newLine();
            $this->warn('These shooters were created without a club_id — attach the club via admin after seeding:');
            foreach ($report['club_missing'] as $miss) {
                $this->line("  • {$miss['shooter']} → wanted '{$miss['club']}'");
            }
        }

        if ($dryRun) {
            $this->newLine();
            $this->comment('DRY RUN — no changes were written.');
        } else {
            $this->newLine();
            $this->comment('Next step: rerun `saprf:seed-national-team-2022` to attach their Factory + Protea Colours rows.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function findExistingUser(array $data): ?User
    {
        $email = strtolower((string) $data['email']);
        $viaEmail = User::whereRaw('LOWER(email) = ?', [$email])->first();
        if ($viaEmail) {
            return $viaEmail;
        }

        if (! empty($data['sa_id_number'])) {
            $viaId = User::where('sa_id_number', $data['sa_id_number'])->first();
            if ($viaId) {
                return $viaId;
            }
        }

        $normalisedName = strtolower((string) preg_replace('/\s+/', ' ', trim((string) $data['name'])));
        $viaName = User::whereRaw('LOWER(name) = ?', [$normalisedName])->first();
        if ($viaName) {
            return $viaName;
        }

        return null;
    }
}
