<?php

/**
 * Playwright fixtures for dashboard hardcore suite.
 *
 * Commands:
 *   seed                 — wipe prior pw fixtures and rebuild
 *   discover             — JSON map of seeded personas
 *   session <user_id> [view_mode]  — encrypted session cookie (view_mode: shooter|admin)
 *   forget <session_id>
 *   cleanup              — remove pw fixtures only
 */

use App\Models\Club;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Membership;
use App\Models\Province;
use App\Models\ProvincialCommittee;
use App\Models\QualificationRule;
use App\Models\RifleConfiguration;
use App\Models\Score;
use App\Models\User;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

if (app()->environment('production')) {
    fwrite(STDERR, "Refusing to run: APP_ENV is production.\n");
    exit(1);
}

$host = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
$localHost = in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($host, '.test');
if (! $localHost) {
    fwrite(STDERR, "Refusing to run: APP_URL host [{$host}] is not local.\n");
    exit(1);
}

$command = $argv[1] ?? '';

match ($command) {
    'seed' => seed(),
    'discover' => discover(),
    'session' => dumpSession((int) ($argv[2] ?? 0), $argv[3] ?? null),
    'forget' => forgetSession($argv[2] ?? ''),
    'cleanup' => cleanup(),
    default => (function () {
        fwrite(STDERR, "Usage: seed | discover | session <user_id> [view_mode] | forget <session_id> | cleanup\n");
        exit(1);
    })(),
};

function emailTag(string $key): string
{
    return "pw.{$key}@saprf.test";
}

function matchPrefix(): string
{
    return 'PW Dash';
}

function cleanup(bool $quiet = false): void
{
    $userIds = User::query()
        ->where('email', 'like', 'pw.%@saprf.test')
        ->pluck('id');

    $matchIds = MatchEvent::query()
        ->where('name', 'like', matchPrefix().'%')
        ->when($userIds->isNotEmpty(), fn ($q) => $q->orWhereIn('created_by', $userIds))
        ->pluck('id');

    if ($matchIds->isNotEmpty()) {
        Score::query()->whereIn('match_id', $matchIds)->delete();
        MatchRegistration::query()->whereIn('match_id', $matchIds)->delete();
        MatchEvent::query()->whereIn('id', $matchIds)->delete();
    }

    if ($userIds->isNotEmpty()) {
        Score::query()->whereIn('user_id', $userIds)->delete();
        MatchRegistration::query()->whereIn('user_id', $userIds)->delete();
        RifleConfiguration::query()->whereIn('user_id', $userIds)->delete();
        Membership::query()->whereIn('user_id', $userIds)->delete();
        ProvincialCommittee::query()->whereIn('user_id', $userIds)->delete();
        QualificationRule::query()->whereIn('created_by', $userIds)->delete();
        User::query()->whereIn('id', $userIds)->forceDelete();
    }

    Club::query()->where('name', 'PW Dashboard Club')->delete();

    if (! $quiet) {
        echo json_encode(['cleaned' => true], JSON_UNESCAPED_SLASHES);
    }
}

function seed(): void
{
    cleanup(quiet: true);

    $season = (string) Carbon::now()->year;
    $gp = Province::query()->where('abbreviation', 'GP')->firstOrFail();
    $wc = Province::query()->where('abbreviation', 'WC')->firstOrFail();

    $club = Club::query()->firstOrCreate(
        ['name' => 'PW Dashboard Club'],
        [
            'slug' => 'pw-dashboard-club-'.Str::lower(Str::random(4)),
            'abbreviation' => 'PWD',
            'province_id' => $gp->id,
            'is_active' => true,
            'saprf_recognised' => true,
        ]
    );

    $creator = makeUser('creator', [
        'name' => 'PW Creator',
        'province_id' => $gp->id,
        'club_id' => $club->id,
        'roles' => ['member'],
        'membership' => false,
    ]);

    QualificationRule::query()->where('series', 'PR22')->where('season', $season)->delete();
    QualificationRule::create([
        'series' => 'PR22',
        'season' => $season,
        'scoring_mode' => 'weighted_pools',
        'min_out_of_province_matches' => 1,
        'provincial_pool_best_of' => 3,
        'national_pool_best_of' => 2,
        'champs_pool_best_of' => 1,
        'created_by' => $creator->id,
    ]);

    $dual = makeUser('dual', [
        'name' => 'PW Dual Shooter',
        'province_id' => $gp->id,
        'club_id' => $club->id,
        'roles' => ['member'],
        'saprf_number' => '90001',
    ]);

    $prs = makeUser('prs', [
        'name' => 'PW PRS Only',
        'province_id' => $gp->id,
        'club_id' => $club->id,
        'roles' => ['member'],
        'saprf_number' => '90002',
    ]);

    $pr22 = makeUser('pr22', [
        'name' => 'PW PR22 Only',
        'province_id' => $gp->id,
        'club_id' => $club->id,
        'roles' => ['member'],
        'saprf_number' => '90003',
    ]);

    $empty = makeUser('empty', [
        'name' => 'PW No Scores',
        'province_id' => $gp->id,
        'club_id' => $club->id,
        'roles' => ['member'],
        'saprf_number' => '90004',
    ]);

    $admin = makeUser('admin', [
        'name' => 'PW Admin',
        'province_id' => $gp->id,
        'club_id' => $club->id,
        'roles' => ['admin', 'member'],
        'saprf_number' => '90005',
    ]);

    $owner = makeUser('owner', [
        'name' => 'PW Owner',
        'province_id' => $gp->id,
        'club_id' => $club->id,
        'roles' => ['owner', 'member'],
        'saprf_number' => '90006',
    ]);

    $md = makeUser('md', [
        'name' => 'PW Match Director',
        'province_id' => $gp->id,
        'club_id' => $club->id,
        'roles' => ['match_director', 'member'],
        'saprf_number' => '90007',
    ]);

    $pa = makeUser('pa', [
        'name' => 'PW Provincial Admin',
        'province_id' => $gp->id,
        'club_id' => $club->id,
        'roles' => ['provincial_admin', 'member'],
        'saprf_number' => '90008',
    ]);

    ProvincialCommittee::create([
        'province_id' => $gp->id,
        'user_id' => $pa->id,
        'position' => 'chair',
        'is_active' => true,
        'appointed_at' => Carbon::today()->subYear(),
    ]);

    // Past scores — dual
    $prsPast = makeMatch([
        'name' => matchPrefix().' PRS Past Dual',
        'series' => 'PRS',
        'match_type' => 'PRS',
        'series_level' => 'national',
        'match_date' => Carbon::today()->subMonths(2),
        'status' => 'completed',
        'province_id' => $gp->id,
        'created_by' => $creator->id,
        'season' => $season,
    ]);
    $pr22Past = makeMatch([
        'name' => matchPrefix().' PR22 Past Dual',
        'series' => 'PR22',
        'match_type' => 'PR22',
        'series_level' => 'provincial',
        'match_date' => Carbon::today()->subMonth(),
        'status' => 'completed',
        'province_id' => $gp->id,
        'created_by' => $creator->id,
        'season' => $season,
    ]);
    makeScore($dual, $prsPast, ['placement' => 3, 'raw_score' => 88]);
    makeScore($dual, $pr22Past, ['placement' => 7, 'raw_score' => 61]);

    // Past scores — PRS only / PR22 only
    $prsOnlyPast = makeMatch([
        'name' => matchPrefix().' PRS Past Solo',
        'series' => 'PRS',
        'match_type' => 'PRS',
        'series_level' => 'national',
        'match_date' => Carbon::today()->subMonths(1),
        'status' => 'completed',
        'province_id' => $gp->id,
        'created_by' => $creator->id,
        'season' => $season,
    ]);
    makeScore($prs, $prsOnlyPast, ['placement' => 12, 'raw_score' => 55]);

    $pr22OnlyPast = makeMatch([
        'name' => matchPrefix().' PR22 Past Solo',
        'series' => 'PR22',
        'match_type' => 'PR22',
        'series_level' => 'provincial',
        'match_date' => Carbon::today()->subWeeks(3),
        'status' => 'completed',
        'province_id' => $gp->id,
        'created_by' => $creator->id,
        'season' => $season,
    ]);
    makeScore($pr22, $pr22OnlyPast, ['placement' => 4, 'raw_score' => 70]);

    // Upcoming matches
    $upcomingPrs = makeMatch([
        'name' => matchPrefix().' Dual Upcoming PRS',
        'series' => 'PRS',
        'match_type' => 'PRS',
        'series_level' => 'national',
        'match_date' => Carbon::today()->addDays(4),
        'status' => 'open',
        'province_id' => $gp->id,
        'created_by' => $md->id,
        'season' => $season,
        'venue_name' => 'Cullinan Range',
    ]);
    $upcomingPr22 = makeMatch([
        'name' => matchPrefix().' Dual Upcoming PR22',
        'series' => 'PR22',
        'match_type' => 'PR22',
        'series_level' => 'provincial',
        'match_date' => Carbon::today()->addDays(5),
        'status' => 'open',
        'province_id' => $gp->id,
        'created_by' => $md->id,
        'season' => $season,
        'venue_name' => 'Benoni Range',
    ]);
    makeMatch([
        'name' => matchPrefix().' PRS Only Upcoming',
        'series' => 'PRS',
        'match_type' => 'PRS',
        'match_date' => Carbon::today()->addDays(6),
        'status' => 'open',
        'province_id' => $gp->id,
        'created_by' => $md->id,
        'season' => $season,
    ]);
    makeMatch([
        'name' => matchPrefix().' PR22 Only Upcoming',
        'series' => 'PR22',
        'match_type' => 'PR22',
        'match_date' => Carbon::today()->addDays(7),
        'status' => 'open',
        'province_id' => $gp->id,
        'created_by' => $md->id,
        'season' => $season,
    ]);
    makeMatch([
        'name' => matchPrefix().' Open PRS NoScores',
        'series' => 'PRS',
        'match_type' => 'PRS',
        'match_date' => Carbon::today()->addDays(8),
        'status' => 'open',
        'province_id' => $gp->id,
        'created_by' => $md->id,
        'season' => $season,
    ]);
    makeMatch([
        'name' => matchPrefix().' Open PR22 NoScores',
        'series' => 'PR22',
        'match_type' => 'PR22',
        'match_date' => Carbon::today()->addDays(9),
        'status' => 'open',
        'province_id' => $gp->id,
        'created_by' => $md->id,
        'season' => $season,
    ]);

    // OOP national for dual PR22 attention — keep it early so it fits the
    // next-matches window (first 3) when the PR22 filter is active.
    makeMatch([
        'name' => matchPrefix().' PR22 OOP National',
        'series' => 'PR22',
        'match_type' => 'PR22',
        'series_level' => 'national',
        'match_date' => Carbon::today()->addDays(3),
        'status' => 'open',
        'province_id' => $wc->id,
        'created_by' => $md->id,
        'season' => $season,
        'registration_open_date' => Carbon::today()->subDay(),
        'venue_name' => 'Cape Town Range',
    ]);

    // Fourth-row registration stretch for dual
    $regA = makeMatch([
        'name' => matchPrefix().' Reg A',
        'series' => 'PRS',
        'match_date' => Carbon::today()->addDays(14),
        'status' => 'open',
        'province_id' => $gp->id,
        'created_by' => $md->id,
        'season' => $season,
    ]);
    $regB = makeMatch([
        'name' => matchPrefix().' Reg B',
        'series' => 'PRS',
        'match_date' => Carbon::today()->addDays(15),
        'status' => 'open',
        'province_id' => $gp->id,
        'created_by' => $md->id,
        'season' => $season,
    ]);
    $regC = makeMatch([
        'name' => matchPrefix().' Reg C',
        'series' => 'PRS',
        'match_date' => Carbon::today()->addDays(16),
        'status' => 'open',
        'province_id' => $gp->id,
        'created_by' => $md->id,
        'season' => $season,
    ]);
    makeMatch([
        'name' => matchPrefix().' Not Entered Yet',
        'series' => 'PRS',
        'match_date' => Carbon::today()->addDays(17),
        'status' => 'open',
        'province_id' => $gp->id,
        'created_by' => $md->id,
        'season' => $season,
    ]);

    foreach ([$regA, $regB, $regC] as $match) {
        MatchRegistration::create([
            'match_id' => $match->id,
            'user_id' => $dual->id,
            'shooter_name' => $dual->name,
            'membership_fee_category' => 'active_member',
            'fee_amount' => 250,
            'registration_status' => 'confirmed',
            'payment_status' => 'paid',
            'registered_at' => now(),
        ]);
    }

    RifleConfiguration::create([
        'user_id' => $dual->id,
        'nickname' => 'PW Centrefire Beast',
        'primary_series' => 'PRS',
        'show_on_profile' => true,
        'is_active' => true,
    ]);
    RifleConfiguration::create([
        'user_id' => $dual->id,
        'nickname' => 'PW Rimfire Whip',
        'primary_series' => 'PR22',
        'show_on_profile' => true,
        'is_active' => true,
    ]);

    // Touch upcoming vars so static analysis stays quiet
    unset($upcomingPrs, $upcomingPr22);

    echo json_encode(discoverPayload(), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

function discover(): void
{
    echo json_encode(discoverPayload(), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

/**
 * @return array<string, mixed>
 */
function discoverPayload(): array
{
    $keys = ['dual', 'prs', 'pr22', 'empty', 'admin', 'owner', 'md', 'pa'];
    $out = [
        'base_url' => rtrim((string) config('app.url'), '/'),
        'season' => (string) Carbon::now()->year,
    ];

    foreach ($keys as $key) {
        $user = User::query()->where('email', emailTag($key))->with(['membership', 'province'])->first();
        $out[$key] = $user ? profile($user) : null;
    }

    return $out;
}

/**
 * @return array<string, mixed>
 */
function profile(User $user): array
{
    return [
        'id' => $user->id,
        'name' => $user->name,
        'email' => $user->email,
        'saprf_number' => $user->membership?->saprf_number,
        'province' => $user->province?->name,
        'roles' => $user->getRoleNames()->values()->all(),
        'can_switch' => $user->canSwitchViewMode(),
    ];
}

/**
 * @param  array{name:string,province_id:int,club_id:?int,roles:list<string>,saprf_number?:string,membership?:bool}  $opts
 */
function makeUser(string $key, array $opts): User
{
    $user = User::query()->updateOrCreate(
        ['email' => emailTag($key)],
        [
            'name' => $opts['name'],
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'must_change_password' => false,
            'is_active' => true,
            'province_id' => $opts['province_id'],
            'club_id' => $opts['club_id'] ?? null,
            'date_of_birth' => Carbon::parse('1990-01-15'),
            'gender' => 'male',
            'ethnicity' => 'white',
            'previously_disadvantaged' => false,
            'sa_citizen' => true,
            'sa_id_number' => sprintf('9001155%s', str_pad((string) (abs(crc32($key)) % 1000000), 6, '0', STR_PAD_LEFT)),
            'country_of_residence' => 'ZA',
            'phone' => '08200000'.str_pad((string) abs(crc32($key) % 100), 2, '0', STR_PAD_LEFT),
        ]
    );

    $user->syncRoles($opts['roles']);

    if (($opts['membership'] ?? true) !== false) {
        Membership::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'saprf_number' => $opts['saprf_number'] ?? null,
                'status' => 'active',
                'payment_status' => 'paid',
                'membership_type' => 'paid',
                'start_date' => Carbon::today()->subMonths(3),
                'expiry_date' => Carbon::today()->addMonths(6),
            ]
        );
    }

    $fresh = $user->fresh(['membership', 'province']);

    if (! $fresh) {
        throw new RuntimeException("Failed to reload user {$user->id} after seed.");
    }

    return $fresh;
}

/**
 * @param  array<string, mixed>  $attrs
 */
function makeMatch(array $attrs): MatchEvent
{
    return MatchEvent::create(array_merge([
        'name' => matchPrefix().' Match',
        'match_type' => 'PRS',
        'series' => 'PRS',
        'series_level' => 'national',
        'season' => (string) Carbon::now()->year,
        'match_date' => Carbon::today()->addDays(10),
        'status' => 'open',
        'published' => true,
        'match_director' => 'PW Director',
        'active_member_fee' => 250,
        'non_member_fee' => 500,
        'lapsed_member_fee' => 375,
        'venue_name' => 'PW Range',
        'city' => 'Pretoria',
    ], $attrs));
}

/**
 * @param  array<string, mixed>  $attrs
 */
function makeScore(User $user, MatchEvent $match, array $attrs = []): Score
{
    return Score::create(array_merge([
        'match_id' => $match->id,
        'user_id' => $user->id,
        'shooter_name' => $user->name,
        'match_date' => $match->match_date,
        'raw_score' => 50,
        'placement' => 5,
        'status' => 'valid',
        'counts_for_season' => true,
    ], $attrs));
}

function dumpSession(int $userId, ?string $viewMode = null): void
{
    if ($userId < 1) {
        fwrite(STDERR, "user_id required\n");
        exit(1);
    }

    $user = User::query()->find($userId);
    if (! $user) {
        fwrite(STDERR, "User {$userId} not found\n");
        exit(1);
    }

    $session = app('session.store');
    $session->start();
    $guard = Auth::guard('web');
    $session->put($guard->getName(), $user->getAuthIdentifier());
    $session->put('password_hash_web', $user->getAuthPassword());

    if ($viewMode !== null && $user->canSwitchViewMode()) {
        $session->put('view_mode', $viewMode);
    } elseif ($user->canSwitchViewMode() && $viewMode === null) {
        // Staff default for this suite is admin shell unless caller asks for shooter.
        $session->put('view_mode', 'admin');
    }

    $session->save();

    $cookieName = (string) config('session.cookie');
    $prefix = CookieValuePrefix::create($cookieName, app('encrypter')->getKey());
    $value = app('encrypter')->encrypt($prefix.$session->getId(), false);

    echo json_encode([
        'cookie' => $cookieName,
        'value' => $value,
        'session_id' => $session->getId(),
        'user_id' => $user->id,
        'name' => $user->name,
        'view_mode' => $session->get('view_mode'),
    ], JSON_UNESCAPED_SLASHES);
}

function forgetSession(string $id): void
{
    if ($id === '' || ! preg_match('/^[A-Za-z0-9]{20,}$/', $id)) {
        fwrite(STDERR, "Refusing to forget a session id that does not look like one we created.\n");
        exit(1);
    }

    $deleted = app('session.store')->getHandler()->destroy($id);

    echo json_encode(['deleted' => (bool) $deleted, 'session_id' => $id]);
}
