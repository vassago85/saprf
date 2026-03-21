<?php

use App\Models\Category;
use App\Models\User;
use App\Services\SeasonClassificationService;
use App\Services\SettingsService;

beforeEach(function () {
    seedRoles();
    Category::create(['slug' => 'overall', 'name' => 'Overall', 'display_order' => 1]);
    Category::create(['slug' => 'ladies', 'name' => 'Ladies', 'display_order' => 2]);
    Category::create(['slug' => 'junior', 'name' => 'Junior', 'display_order' => 3]);
    Category::create(['slug' => 'senior', 'name' => 'Senior', 'display_order' => 4]);
});

it('classifies a junior shooter who turns 18 during season as junior for the full season', function () {
    $settings = app(SettingsService::class);
    $settings->set('age_classification_date_mode', 'first_day_of_calendar_year');
    $settings->set('season_locked_age_categories', '1');
    $settings->set('prs_junior_max_age', '21');
    $settings->set('pr22_junior_max_age', '18');

    $user = User::factory()->create(['date_of_birth' => '2008-06-15']);

    $service = app(SeasonClassificationService::class);
    $classification = $service->classifyShooterForSeason($user, '2026');

    expect($classification->age_on_classification_date)->toBe(17);
    expect($classification->is_locked)->toBeTrue();
    expect($classification->categories->pluck('slug')->toArray())->toContain('junior');
});

it('classifies a junior who turns 21 during season as junior for the full season', function () {
    $settings = app(SettingsService::class);
    $settings->set('age_classification_date_mode', 'first_day_of_calendar_year');
    $settings->set('season_locked_age_categories', '1');
    $settings->set('prs_junior_max_age', '21');

    $user = User::factory()->create(['date_of_birth' => '2005-09-10']);

    $service = app(SeasonClassificationService::class);
    $classification = $service->classifyShooterForSeason($user, '2026');

    expect($classification->age_on_classification_date)->toBe(20);
    expect($classification->is_locked)->toBeTrue();
    expect($classification->categories->pluck('slug')->toArray())->toContain('junior');
});

it('uses series-specific junior threshold: 19yo is junior for PRS but not PR22', function () {
    $settings = app(SettingsService::class);
    $settings->set('prs_junior_max_age', '21');
    $settings->set('pr22_junior_max_age', '18');

    $service = app(SeasonClassificationService::class);

    $prsCategories = $service->getAgeBasedCategories(19, 'PRS');
    $pr22Categories = $service->getAgeBasedCategories(19, 'PR22');

    expect($prsCategories->pluck('slug')->toArray())->toContain('junior');
    expect($pr22Categories->pluck('slug')->toArray())->not->toContain('junior');
});

it('classifies 17yo as junior for both PRS and PR22', function () {
    $settings = app(SettingsService::class);
    $settings->set('prs_junior_max_age', '21');
    $settings->set('pr22_junior_max_age', '18');

    $service = app(SeasonClassificationService::class);

    expect($service->getAgeBasedCategories(17, 'PRS')->pluck('slug')->toArray())->toContain('junior');
    expect($service->getAgeBasedCategories(17, 'PR22')->pluck('slug')->toArray())->toContain('junior');
});

it('does not move a shooter to senior mid-season', function () {
    $settings = app(SettingsService::class);
    $settings->set('age_classification_date_mode', 'first_day_of_calendar_year');
    $settings->set('season_locked_age_categories', '1');
    $settings->set('senior_min_age', '55');

    $user = User::factory()->create(['date_of_birth' => '1971-03-20']);

    $service = app(SeasonClassificationService::class);
    $classification = $service->classifyShooterForSeason($user, '2026');

    expect($classification->age_on_classification_date)->toBe(54);
    expect($classification->categories->pluck('slug')->toArray())->not->toContain('senior');
});

it('classifies shooter as senior when already 55 on classification date', function () {
    $settings = app(SettingsService::class);
    $settings->set('age_classification_date_mode', 'first_day_of_calendar_year');
    $settings->set('senior_min_age', '55');

    $user = User::factory()->create(['date_of_birth' => '1970-06-15']);

    $service = app(SeasonClassificationService::class);
    $classification = $service->classifyShooterForSeason($user, '2026');

    expect($classification->age_on_classification_date)->toBe(55);
    expect($classification->categories->pluck('slug')->toArray())->toContain('senior');
});

it('respects custom classification date mode', function () {
    $settings = app(SettingsService::class);
    $settings->set('age_classification_date_mode', 'custom_date');
    $settings->set('age_classification_custom_date', '2026-04-01');
    $settings->set('prs_junior_max_age', '21');

    $user = User::factory()->create(['date_of_birth' => '2008-03-15']);

    $service = app(SeasonClassificationService::class);
    $classification = $service->classifyShooterForSeason($user, '2026');

    expect($classification->age_on_classification_date)->toBe(18);
    expect($classification->categories->pluck('slug')->toArray())->toContain('junior');
});

it('prevents reclassification when season is locked', function () {
    $settings = app(SettingsService::class);
    $settings->set('age_classification_date_mode', 'first_day_of_calendar_year');
    $settings->set('season_locked_age_categories', '1');
    $settings->set('prs_junior_max_age', '21');

    $user = User::factory()->create(['date_of_birth' => '2008-06-15']);

    $service = app(SeasonClassificationService::class);
    $first = $service->classifyShooterForSeason($user, '2026');

    expect($first->is_locked)->toBeTrue();

    $second = $service->classifyShooterForSeason($user, '2026');
    expect($second->id)->toBe($first->id);
    expect($second->age_on_classification_date)->toBe($first->age_on_classification_date);
});

it('allows admin to override classification with audit trail', function () {
    $settings = app(SettingsService::class);
    $settings->set('age_classification_date_mode', 'first_day_of_calendar_year');
    $settings->set('prs_junior_max_age', '21');

    $admin = User::factory()->create();
    $admin->assignRole('owner');

    $user = User::factory()->create(['date_of_birth' => '2008-06-15']);
    $ladiesCategory = Category::where('slug', 'ladies')->first();

    $service = app(SeasonClassificationService::class);
    $classification = $service->classifyShooterForSeason($user, '2026');

    $service->overrideClassification($classification, [$ladiesCategory->id], 'Manual override for testing', $admin);

    $classification->refresh();
    expect($classification->override_applied)->toBeTrue();
    expect($classification->override_reason)->toBe('Manual override for testing');
    expect($classification->categories->pluck('slug')->toArray())->toBe(['ladies']);
});
