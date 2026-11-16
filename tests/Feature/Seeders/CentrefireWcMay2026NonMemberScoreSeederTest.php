<?php

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\Membership;
use App\Models\Province;
use App\Models\Score;
use App\Models\User;
use Database\Seeders\CentrefireWcMay2026NonMemberScoreSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    seedRoles();
});

it('drops a waived import membership so Francois stays a non-member', function () {
    $open = Division::create([
        'slug' => 'open',
        'name' => 'Open',
        'display_order' => 1,
    ]);
    $director = User::create([
        'name' => 'Match Director',
        'email' => 'md2@example.test',
        'password' => Hash::make('secret'),
        'is_active' => true,
    ]);
    $match = MatchEvent::create([
        'name' => 'Centrefire WC 2-Day National',
        'slug' => 'wc-national-francois-non-member',
        'match_type' => 'PRS',
        'series' => 'PRS',
        'season' => '2026',
        'series_level' => 'national',
        'match_date' => '2026-05-16',
        'status' => 'completed',
        'published' => true,
        'created_by' => $director->id,
    ]);

    $dirkUser = memberShooter('Dirk Van Staden', 'dirk2@example.test', $open->id);
    scoreOn($match, $dirkUser, 112, 100);

    $westernCape = Province::create(['name' => 'Western Cape', 'abbreviation' => 'WC']);
    $francois = User::create([
        'name' => 'Francois Marais',
        'email' => 'francois.marais@import.saprf.local',
        'password' => Hash::make('secret'),
        'division_id' => $open->id,
        'province_id' => $westernCape->id,
        'country_of_residence' => 'ZA',
        'sa_citizen' => true,
        'is_active' => true,
    ]);
    $francois->assignRole('member');
    Membership::create([
        'user_id' => $francois->id,
        'saprf_number' => 'SAPRF-2026-20005',
        'membership_type' => 'paid',
        'status' => 'active',
        'payment_status' => 'waived',
        'start_date' => '2026-01-01',
        'expiry_date' => '2026-12-31',
    ]);
    $score = scoreOn($match, $francois, 116, 100);

    Artisan::call('db:seed', ['--class' => CentrefireWcMay2026NonMemberScoreSeeder::class, '--force' => true]);

    $score->refresh();

    $francois->refresh();

    expect($score->status)->toBe('non_member')
        ->and($score->is_member)->toBeFalse()
        ->and((float) $score->raw_score)->toBe(116.0)
        ->and($francois->country_of_residence)->toBe('NA')
        ->and($francois->sa_citizen)->toBeFalse()
        ->and($francois->province_id)->toBeNull()
        ->and(Membership::query()->where('user_id', $francois->id)->exists())->toBeFalse()
        ->and(Membership::query()->where('user_id', $dirkUser->id)->exists())->toBeTrue();

    $this->get(route('events.show', $match))
        ->assertOk()
        ->assertSee('Namibia')
        ->assertSee('Non-member');
});

it('adds the legacy non-members so the overall high score becomes 116', function () {
    $open = Division::create([
        'slug' => 'open',
        'name' => 'Open',
        'display_order' => 1,
    ]);

    $director = User::create([
        'name' => 'Match Director',
        'email' => 'md@example.test',
        'password' => Hash::make('secret'),
        'is_active' => true,
    ]);

    $match = MatchEvent::create([
        'name' => 'Centrefire WC 2-Day National',
        'slug' => 'wc-national-2026-05-16',
        'match_type' => 'PRS',
        'series' => 'PRS',
        'season' => '2026',
        'series_level' => 'national',
        'match_date' => '2026-05-16',
        'match_end_date' => '2026-05-17',
        'status' => 'completed',
        'published' => true,
        'created_by' => $director->id,
    ]);

    $dirkUser = memberShooter('Dirk Van Staden', 'dirk@example.test', $open->id);
    $johanUser = memberShooter('Johan Nel', 'johan@example.test', $open->id);

    $dirk = scoreOn($match, $dirkUser, 112, 100);
    $johan = scoreOn($match, $johanUser, 110, 98.2143);

    $other = MatchEvent::create([
        'name' => 'Centrefire GP Provincial',
        'slug' => 'gp-provincial-2026-01-17',
        'match_type' => 'PRS',
        'series' => 'PRS',
        'season' => '2026',
        'series_level' => 'provincial',
        'match_date' => '2026-01-17',
        'status' => 'completed',
        'published' => true,
        'created_by' => $director->id,
    ]);
    $otherWinner = scoreOn($other, $dirkUser, 80, 50);
    $otherSecond = scoreOn($other, $johanUser, 40, 10);

    Artisan::call('db:seed', ['--class' => CentrefireWcMay2026NonMemberScoreSeeder::class, '--force' => true]);

    $dirk->refresh();
    $johan->refresh();

    expect((float) $dirk->raw_score)->toBe(112.0)
        ->and($dirk->status)->toBe('valid')
        ->and(round((float) $dirk->normalized_score, 4))->toBe(96.5517)
        ->and($dirk->overall_rank)->toBe(2)
        ->and(round((float) $johan->normalized_score, 4))->toBe(94.8276)
        ->and($johan->overall_rank)->toBe(3);

    $francois = Score::query()->where('match_id', $match->id)->where('shooter_name', 'Francois Marais')->first();
    $sauber = Score::query()->where('match_id', $match->id)->where('shooter_name', 'Dirk Sauber')->first();
    $louw = Score::query()->where('match_id', $match->id)->where('shooter_name', 'Derick Louw')->first();
    $marius = Score::query()->where('match_id', $match->id)->where('shooter_name', 'Marius j')->first();

    expect($francois)->not->toBeNull()
        ->and((float) $francois->raw_score)->toBe(116.0)
        ->and($francois->status)->toBe('non_member')
        ->and($francois->is_member)->toBeFalse()
        ->and($francois->overall_rank)->toBe(1)
        ->and($francois->division_id)->toBe($open->id)
        ->and($francois->user->membership)->toBeNull()
        ->and((float) $sauber->raw_score)->toBe(106.0)
        ->and($sauber->status)->toBe('non_member')
        ->and($sauber->division_id)->toBeNull()
        ->and($sauber->overall_rank)->toBe(4)
        ->and((float) $louw->raw_score)->toBe(65.0)
        ->and($louw->status)->toBe('non_member')
        ->and((float) $marius->raw_score)->toBe(12.0)
        ->and($marius->status)->toBe('non_member');

    expect(Score::query()->where('match_id', $match->id)->count())->toBe(6)
        ->and(Membership::query()->count())->toBe(2)
        ->and(round((float) $otherWinner->fresh()->normalized_score, 4))->toBe(100.0)
        ->and($otherWinner->fresh()->overall_rank)->toBe(1)
        ->and(round((float) $otherSecond->fresh()->normalized_score, 4))->toBe(50.0)
        ->and($otherSecond->fresh()->overall_rank)->toBe(2);

    Artisan::call('db:seed', ['--class' => CentrefireWcMay2026NonMemberScoreSeeder::class, '--force' => true]);

    expect(Score::query()->where('match_id', $match->id)->count())->toBe(6)
        ->and(User::query()->where('email', 'like', 'legacy.%@saprf.walkin')->count())->toBe(4)
        ->and((float) $dirk->fresh()->raw_score)->toBe(112.0);
});

function memberShooter(string $name, string $email, int $divisionId): User
{
    $user = User::create([
        'name' => $name,
        'email' => $email,
        'password' => Hash::make('secret'),
        'division_id' => $divisionId,
        'is_active' => true,
    ]);
    $user->assignRole('member');
    giveActiveMembership($user);

    return $user;
}

function scoreOn(MatchEvent $match, User $user, float $raw, float $normalized): Score
{
    return Score::create([
        'match_id' => $match->id,
        'user_id' => $user->id,
        'shooter_name' => $user->name,
        'division_id' => $user->division_id,
        'raw_score' => $raw,
        'normalized_score' => $normalized,
        'status' => 'valid',
        'is_member' => true,
        'counts_for_log' => true,
        'counts_for_season' => true,
        'match_date' => $match->match_date,
    ]);
}
