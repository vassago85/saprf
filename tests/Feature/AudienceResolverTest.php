<?php

/**
 * Audience resolution is the single foundational piece of the Notification
 * Centre — everything else (preview, freeze, dispatch) trusts its output.
 * Every failure mode here needs to be a direct assertion, not something
 * caught by a downstream test.
 */

use App\Enums\AudienceMode;
use App\Enums\AudienceType;
use App\Models\Club;
use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Membership;
use App\Models\MembershipFeeTier;
use App\Models\Province;
use App\Models\SavedDistributionList;
use App\Models\User;
use App\Services\Announcements\AudienceResolver;

beforeEach(function () {
    seedRoles();
});

// ── Helpers ────────────────────────────────────────────────────────────────

function makeMember(array $userOverrides = [], array $membershipOverrides = []): User
{
    static $counter = 0;
    $counter++;

    $user = User::factory()->create(array_merge([
        'email_verified_at' => now(),
    ], $userOverrides));

    $user->assignRole('member');

    Membership::create(array_merge([
        'user_id' => $user->id,
        'saprf_number' => 'AR-'.$user->id.'-'.$counter,
        'membership_type' => 'paid',
        'status' => 'active',
        'payment_status' => 'paid',
        'expiry_date' => now()->addYear()->toDateString(),
    ], $membershipOverrides));

    return $user->fresh();
}

function rule(AudienceType $type, array $value = [], AudienceMode $mode = AudienceMode::Include): array
{
    return ['type' => $type, 'value' => $value, 'mode' => $mode];
}

// ── active_members vs expired ─────────────────────────────────────────────

it('active_members includes currently paid members and skips expired ones', function () {
    $active = makeMember();
    $expired = makeMember(membershipOverrides: [
        'expiry_date' => now()->subDay()->toDateString(),
    ]);
    $revoked = makeMember(membershipOverrides: [
        'status' => 'revoked',
    ]);

    $ids = app(AudienceResolver::class)->resolve([
        rule(AudienceType::ActiveMembers),
    ]);

    expect($ids->all())->toEqual([$active->id]);
});

// ── include + exclude composition ─────────────────────────────────────────

it('subtracts an exclude rule from the include set', function () {
    $rank = makeMember();
    $excoUser = makeMember();
    $excoUser->assignRole('exco');

    $ids = app(AudienceResolver::class)->resolve([
        rule(AudienceType::ActiveMembers),
        rule(AudienceType::Role, ['role' => 'exco'], AudienceMode::Exclude),
    ]);

    expect($ids->all())->toEqual([$rank->id]);
});

// ── empty include = zero recipients (no implicit "everyone") ─────────────

it('returns an empty list when only exclude rules are supplied', function () {
    makeMember();
    makeMember();

    $ids = app(AudienceResolver::class)->resolve([
        rule(AudienceType::Role, ['role' => 'member'], AudienceMode::Exclude),
    ]);

    expect($ids->isEmpty())->toBeTrue();
});

it('returns an empty list when no rules are supplied at all', function () {
    makeMember();

    expect(app(AudienceResolver::class)->resolve([])->isEmpty())->toBeTrue();
});

// ── division targeting ────────────────────────────────────────────────────

it('targets by division', function () {
    $factory = Division::firstOrCreate(['slug' => 'factory'], ['name' => 'Factory']);
    $open = Division::firstOrCreate(['slug' => 'open'], ['name' => 'Open']);

    $inFactory = makeMember(userOverrides: ['division_id' => $factory->id]);
    $inOpen = makeMember(userOverrides: ['division_id' => $open->id]);

    $ids = app(AudienceResolver::class)->resolve([
        rule(AudienceType::Division, ['division_id' => $factory->id]),
    ]);

    expect($ids->all())->toEqual([$inFactory->id]);
});

// ── province + club ────────────────────────────────────────────────────────

it('targets by province', function () {
    $gp = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);
    $wc = Province::firstOrCreate(['name' => 'Western Cape'], ['abbreviation' => 'WC']);

    $inGp = makeMember(userOverrides: ['province_id' => $gp->id]);
    $inWc = makeMember(userOverrides: ['province_id' => $wc->id]);

    $ids = app(AudienceResolver::class)->resolve([
        rule(AudienceType::Province, ['province_id' => $gp->id]),
    ]);

    expect($ids->all())->toEqual([$inGp->id]);
});

it('targets by club', function () {
    $gp = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);
    $clubA = Club::create(['name' => 'Alpha Rifles', 'slug' => 'alpha-rifles', 'province_id' => $gp->id]);
    $clubB = Club::create(['name' => 'Bravo Range', 'slug' => 'bravo-range', 'province_id' => $gp->id]);

    $inA = makeMember(userOverrides: ['club_id' => $clubA->id]);
    $inB = makeMember(userOverrides: ['club_id' => $clubB->id]);

    $ids = app(AudienceResolver::class)->resolve([
        rule(AudienceType::Club, ['club_id' => $clubA->id]),
    ]);

    expect($ids->all())->toEqual([$inA->id]);
});

// ── named individuals + saved lists ───────────────────────────────────────

it('expands named individuals', function () {
    $a = makeMember();
    $b = makeMember();
    $c = makeMember();

    $ids = app(AudienceResolver::class)->resolve([
        rule(AudienceType::Individual, ['user_ids' => [$a->id, $c->id]]),
    ]);

    expect($ids->all())->toEqual([$a->id, $c->id]);
});

it('expands a saved distribution list inline', function () {
    $one = makeMember();
    $two = makeMember();
    $three = makeMember();

    $list = SavedDistributionList::create([
        'name' => 'Test list',
        'rules' => [
            [
                'type' => AudienceType::Individual->value,
                'value' => ['user_ids' => [$one->id, $two->id]],
                'mode' => AudienceMode::Include->value,
            ],
        ],
    ]);

    $ids = app(AudienceResolver::class)->resolve([
        rule(AudienceType::SavedList, ['list_id' => $list->id]),
    ]);

    expect($ids->all())->toEqual([$one->id, $two->id]);
});

// ── uniqueness + soft delete ──────────────────────────────────────────────

it('returns each user exactly once even when multiple include rules match', function () {
    $gp = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);
    $factory = Division::firstOrCreate(['slug' => 'factory'], ['name' => 'Factory']);

    $overlap = makeMember(userOverrides: [
        'province_id' => $gp->id,
        'division_id' => $factory->id,
    ]);

    $ids = app(AudienceResolver::class)->resolve([
        rule(AudienceType::Province, ['province_id' => $gp->id]),
        rule(AudienceType::Division, ['division_id' => $factory->id]),
    ]);

    expect($ids->all())->toEqual([$overlap->id]);
});

it('excludes soft-deleted users', function () {
    $alive = makeMember();
    $dead = makeMember();

    $dead->delete();

    $ids = app(AudienceResolver::class)->resolve([
        rule(AudienceType::Individual, ['user_ids' => [$alive->id, $dead->id]]),
    ]);

    expect($ids->all())->toEqual([$alive->id]);
});

// ── series (PRS vs PR22) ──────────────────────────────────────────────────

it('resolves series audiences by match registrations in the given season', function () {
    $gp = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);

    $prsShooter = makeMember();
    $pr22Shooter = makeMember();
    $bystander = makeMember();

    $prsMatch = MatchEvent::create([
        'name' => 'PRS Provincial',
        'match_type' => 'PRS',
        'series_level' => 'provincial',
        'series' => 'PRS',
        'season' => (string) now()->year,
        'province_id' => $gp->id,
        'match_date' => now()->subMonth()->toDateString(),
        'status' => 'open',
        'published' => true,
        'active_member_fee' => 550.00,
        'created_by' => $prsShooter->id,
    ]);

    $pr22Match = MatchEvent::create([
        'name' => 'PR22 Provincial',
        'match_type' => 'PR22',
        'series_level' => 'provincial',
        'series' => 'PR22',
        'season' => (string) now()->year,
        'province_id' => $gp->id,
        'match_date' => now()->subMonth()->toDateString(),
        'status' => 'open',
        'published' => true,
        'active_member_fee' => 550.00,
        'created_by' => $pr22Shooter->id,
    ]);

    MatchRegistration::create([
        'match_id' => $prsMatch->id,
        'user_id' => $prsShooter->id,
        'shooter_name' => $prsShooter->name,
        'membership_fee_category' => 'active_member',
        'fee_amount' => 550,
        'payment_status' => 'paid',
        'registration_status' => 'confirmed',
        'registered_at' => now(),
    ]);

    MatchRegistration::create([
        'match_id' => $pr22Match->id,
        'user_id' => $pr22Shooter->id,
        'shooter_name' => $pr22Shooter->name,
        'membership_fee_category' => 'active_member',
        'fee_amount' => 550,
        'payment_status' => 'paid',
        'registration_status' => 'confirmed',
        'registered_at' => now(),
    ]);

    $prsIds = app(AudienceResolver::class)->resolve([
        rule(AudienceType::Series, ['series' => 'PRS', 'season' => (string) now()->year]),
    ]);

    $pr22Ids = app(AudienceResolver::class)->resolve([
        rule(AudienceType::Series, ['series' => 'PR22', 'season' => (string) now()->year]),
    ]);

    expect($prsIds->all())->toEqual([$prsShooter->id])
        ->and($pr22Ids->all())->toEqual([$pr22Shooter->id]);
});

// ── membership_type / fee_tier ────────────────────────────────────────────

it('targets by membership type', function () {
    $paid = makeMember();
    $free = makeMember(membershipOverrides: ['membership_type' => 'free']);

    $ids = app(AudienceResolver::class)->resolve([
        rule(AudienceType::MembershipType, ['membership_type' => 'paid']),
    ]);

    expect($ids->all())->toEqual([$paid->id]);
});

it('targets by fee tier', function () {
    $adult = MembershipFeeTier::firstOrCreate(['slug' => 'adult'], [
        'name' => 'Adult',
        'annual_fee' => 500,
        'display_order' => 1,
    ]);
    $senior = MembershipFeeTier::firstOrCreate(['slug' => 'senior'], [
        'name' => 'Senior',
        'annual_fee' => 300,
        'display_order' => 2,
    ]);

    $adultMember = makeMember(membershipOverrides: ['fee_tier_id' => $adult->id]);
    $seniorMember = makeMember(membershipOverrides: ['fee_tier_id' => $senior->id]);

    $ids = app(AudienceResolver::class)->resolve([
        rule(AudienceType::FeeTier, ['fee_tier_id' => $senior->id]),
    ]);

    expect($ids->all())->toEqual([$seniorMember->id]);
});

// ── preview ────────────────────────────────────────────────────────────────

it('preview returns count plus a bounded sample of users', function () {
    $members = collect(range(1, 5))->map(fn () => makeMember());

    $preview = app(AudienceResolver::class)->preview([
        rule(AudienceType::ActiveMembers),
    ], sample: 3);

    expect($preview->count)->toBe(5)
        ->and($preview->sample)->toHaveCount(3);
});
