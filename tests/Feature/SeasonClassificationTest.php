<?php

use App\Models\Category;
use App\Models\SeasonShooterClassification;
use App\Models\User;
use App\Services\SeasonClassificationService;
use App\Services\SettingsService;

beforeEach(function () {
    seedRoles();
    Category::create(['code' => 'sub-junior', 'name' => 'Sub-Junior', 'is_age_based' => true, 'max_age' => 14, 'display_order' => 1]);
    Category::create(['code' => 'junior', 'name' => 'Junior', 'is_age_based' => true, 'min_age' => 15, 'max_age' => 21, 'display_order' => 2]);
    Category::create(['code' => 'senior', 'name' => 'Senior', 'is_age_based' => true, 'min_age' => 55, 'max_age' => 64, 'display_order' => 3]);
    Category::create(['code' => 'super-senior', 'name' => 'Super Senior', 'is_age_based' => true, 'min_age' => 65, 'display_order' => 4]);
    Category::create(['code' => 'lady', 'name' => 'Lady', 'is_age_based' => false, 'display_order' => 5]);
});

it('classifies a PR22 junior who turns 18 during season as junior for the full season', function () {
    $settings = app(SettingsService::class);
    $settings->set('age_classification_date_mode', 'first_day_of_calendar_year');
    $settings->set('season_locked_age_categories', '1');

    // Turns 18 in June 2026 — on Jan 1 2026 they are 17
    $user = User::factory()->create(['date_of_birth' => '2008-06-15']);

    $service = app(SeasonClassificationService::class);
    $classification = $service->classifyShooterForSeason($user, '2026', 'PR22');

    expect($classification->age_on_classification_date)->toBe(17);
    expect($classification->is_locked)->toBeTrue();
    expect($classification->categories->pluck('code')->toArray())->toContain('junior');
});

it('classifies a PRS junior who turns 21 during season as junior for the full season', function () {
    $settings = app(SettingsService::class);
    $settings->set('age_classification_date_mode', 'first_day_of_calendar_year');
    $settings->set('season_locked_age_categories', '1');

    // Turns 21 in September 2026 — on Jan 1 2026 they are 20
    $user = User::factory()->create(['date_of_birth' => '2005-09-10']);

    $service = app(SeasonClassificationService::class);
    $classification = $service->classifyShooterForSeason($user, '2026', 'PRS');

    expect($classification->age_on_classification_date)->toBe(20);
    expect($classification->is_locked)->toBeTrue();
    expect($classification->categories->pluck('code')->toArray())->toContain('junior');
});

it('does not move a shooter to senior mid-season', function () {
    $settings = app(SettingsService::class);
    $settings->set('age_classification_date_mode', 'first_day_of_calendar_year');
    $settings->set('season_locked_age_categories', '1');

    // Turns 55 in March 2026 — on Jan 1 2026 they are 54 (not yet senior)
    $user = User::factory()->create(['date_of_birth' => '1971-03-20']);

    $service = app(SeasonClassificationService::class);
    $classification = $service->classifyShooterForSeason($user, '2026', 'PRS');

    expect($classification->age_on_classification_date)->toBe(54);
    expect($classification->categories->pluck('code')->toArray())->not->toContain('senior');
});

it('classifies shooter as senior when already 55 on classification date', function () {
    $settings = app(SettingsService::class);
    $settings->set('age_classification_date_mode', 'first_day_of_calendar_year');

    // Born 1970 — on Jan 1 2026 they are 55
    $user = User::factory()->create(['date_of_birth' => '1970-06-15']);

    $service = app(SeasonClassificationService::class);
    $classification = $service->classifyShooterForSeason($user, '2026', 'PRS');

    expect($classification->age_on_classification_date)->toBe(55);
    expect($classification->categories->pluck('code')->toArray())->toContain('senior');
});

it('respects custom classification date mode', function () {
    $settings = app(SettingsService::class);
    $settings->set('age_classification_date_mode', 'custom_date');
    $settings->set('age_classification_custom_date', '2026-04-01');

    // Born 2008-03-15 — on Apr 1 2026 they are 18 (just turned 18 in March)
    $user = User::factory()->create(['date_of_birth' => '2008-03-15']);

    $service = app(SeasonClassificationService::class);
    $classification = $service->classifyShooterForSeason($user, '2026', 'PR22');

    expect($classification->age_on_classification_date)->toBe(18);
    // Age 18 is above PR22 junior max (should NOT be junior)
    // Junior max_age is 21 in our seed but the Category seed in beforeEach has max_age=21
    // Age 18 is between 15-21, so still junior
    expect($classification->categories->pluck('code')->toArray())->toContain('junior');
});

it('prevents reclassification when season is locked', function () {
    $settings = app(SettingsService::class);
    $settings->set('age_classification_date_mode', 'first_day_of_calendar_year');
    $settings->set('season_locked_age_categories', '1');

    $user = User::factory()->create(['date_of_birth' => '2008-06-15']);

    $service = app(SeasonClassificationService::class);
    $first = $service->classifyShooterForSeason($user, '2026', 'PR22');

    expect($first->is_locked)->toBeTrue();

    // Try to reclassify — should return same locked classification
    $second = $service->classifyShooterForSeason($user, '2026', 'PR22');
    expect($second->id)->toBe($first->id);
    expect($second->age_on_classification_date)->toBe($first->age_on_classification_date);
});

it('allows admin to override classification with audit trail', function () {
    $settings = app(SettingsService::class);
    $settings->set('age_classification_date_mode', 'first_day_of_calendar_year');

    $admin = User::factory()->create();
    $admin->assignRole('owner');

    $user = User::factory()->create(['date_of_birth' => '2008-06-15']);
    $ladyCategory = Category::where('code', 'lady')->first();

    $service = app(SeasonClassificationService::class);
    $classification = $service->classifyShooterForSeason($user, '2026', 'PRS');

    $service->overrideClassification($classification, [$ladyCategory->id], 'Manual override for testing', $admin);

    $classification->refresh();
    expect($classification->override_applied)->toBeTrue();
    expect($classification->override_reason)->toBe('Manual override for testing');
    expect($classification->categories->pluck('code')->toArray())->toBe(['lady']);
});
