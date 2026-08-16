<?php

use App\Models\AuditLog;
use App\Models\SelectionAthlete;
use App\Models\SelectionCycle;
use App\Models\SelectionDeclaration;
use App\Models\User;
use App\Notifications\SelectionDeclarationSubmittedNotification;
use App\Services\Selection\PolicyImportService;
use Illuminate\Support\Facades\Notification;

/**
 * Coverage for the shooter-facing IPRF dashboard: opt-in, withdraw, form
 * submission (which counts as ExCo receipt), and read-only handling for
 * closed cycles. Also asserts that every state change lands in the audit log
 * so nothing about a shooter's declaration is untraceable.
 */
beforeEach(function () {
    seedRoles();
    Notification::fake();
});

function makeShooterCycle(string $status = 'open'): SelectionCycle
{
    $cycle = SelectionCycle::create([
        'series' => 'PR22',
        'season' => '2027',
        'championship_name' => 'IPRF PR22 Team World Championships 2027',
        'qualifying_period_start' => '2026-01-01',
        'qualifying_period_end' => '2026-12-31',
        'declaration_deadline' => '2026-11-30 23:59:00',
        'panel_lock_date' => '2027-01-12',
        'deliberation_start' => '2027-01-16',
        'deliberation_end' => '2027-01-28',
        'results_freeze' => '2026-12-31',
        'publication_date' => '2027-01-30',
        'status' => $status,
        'evaluation_mode' => SelectionCycle::MODE_ASSUME_QUALIFIED,
    ]);

    app(PolicyImportService::class)->import(
        base_path('docs/selection/pr22/2027/policy.json'),
        $cycle,
    );

    return $cycle->fresh(['activePolicy']);
}

function makeShooterUser(): User
{
    $user = User::factory()->create([
        'name' => 'Alex Shooter',
        'sa_citizen' => true,
        'country_of_residence' => 'ZA',
        'email_verified_at' => now(),
    ]);
    $user->assignRole('member');

    return $user;
}

it('shows the IPRF dashboard to authenticated members and lists open cycles', function () {
    $cycle = makeShooterCycle();
    $user = makeShooterUser();

    $this->actingAs($user)
        ->get(route('iprf.index'))
        ->assertOk()
        ->assertSee('Team Selection')
        ->assertSee('IPRF PR22 Team World Championships 2027');
});

it('rejects a guest hitting the IPRF dashboard', function () {
    makeShooterCycle();

    $this->get(route('iprf.index'))
        ->assertRedirect(route('login'));
});

it('opts a shooter into an open cycle and writes an audit log entry', function () {
    $cycle = makeShooterCycle();
    $user = makeShooterUser();

    $this->actingAs($user)
        ->post(route('iprf.opt-in', $cycle))
        ->assertRedirect(route('iprf.index'));

    $athlete = SelectionAthlete::forCycle($cycle->id)->where('user_id', $user->id)->first();
    expect($athlete)->not->toBeNull()
        ->and($athlete->state)->toBe(SelectionAthlete::STATE_REGISTERED);

    expect(AuditLog::where('action_type', 'selection_athlete_self_opted_in')
        ->where('entity_id', $athlete->id)
        ->count())->toBe(1);
});

it('is a no-op when opting in twice', function () {
    $cycle = makeShooterCycle();
    $user = makeShooterUser();

    $this->actingAs($user)->post(route('iprf.opt-in', $cycle));
    $this->actingAs($user)->post(route('iprf.opt-in', $cycle));

    expect(SelectionAthlete::forCycle($cycle->id)->where('user_id', $user->id)->count())->toBe(1);
});

it('rejects opt-in on a closed cycle', function () {
    $cycle = makeShooterCycle('closed');
    $user = makeShooterUser();

    $this->actingAs($user)
        ->post(route('iprf.opt-in', $cycle))
        ->assertForbidden();

    expect(SelectionAthlete::forCycle($cycle->id)->where('user_id', $user->id)->count())->toBe(0);
});

it('accepts a fully-attested form and marks it received by ExCo', function () {
    $cycle = makeShooterCycle();
    $user = makeShooterUser();
    $this->actingAs($user)->post(route('iprf.opt-in', $cycle));

    $response = $this->actingAs($user)->post(route('iprf.form', $cycle), [
        'intention_to_participate' => '1',
        'able_and_willing' => '1',
        'satisfy_preconditions' => '1',
        'no_impairment' => '1',
        'signature' => $user->name,
        'notes' => 'Please contact me on the listed number.',
    ]);
    $response->assertRedirect(route('iprf.index'));

    $athlete = SelectionAthlete::forCycle($cycle->id)->where('user_id', $user->id)->firstOrFail();
    $declaration = $athlete->declaration;

    expect($declaration)->not->toBeNull()
        ->and($declaration->status)->toBe(SelectionDeclaration::STATUS_SUBMITTED)
        ->and($declaration->submitted_at)->not->toBeNull()
        ->and($declaration->form_data['eligibility_to_compete_received'] ?? false)->toBeTrue()
        ->and($declaration->form_data['received_channel'] ?? null)->toBe('online_form')
        ->and($declaration->form_data['attestations']['intention_to_participate'] ?? false)->toBeTrue()
        ->and($declaration->form_data['attestations']['able_and_willing'] ?? false)->toBeTrue()
        ->and($declaration->form_data['attestations']['satisfy_preconditions'] ?? false)->toBeTrue()
        ->and($declaration->form_data['attestations']['no_impairment'] ?? false)->toBeTrue()
        ->and($declaration->form_data['signature'] ?? null)->toBe($user->name);

    // Audit log for the submitted declaration.
    expect(AuditLog::where('action_type', 'selection_declaration_submitted_online')
        ->where('entity_id', $declaration->id)
        ->count())->toBe(1);
});

it('rejects a form submission where any attestation is missing', function () {
    $cycle = makeShooterCycle();
    $user = makeShooterUser();
    $this->actingAs($user)->post(route('iprf.opt-in', $cycle));

    $this->actingAs($user)->post(route('iprf.form', $cycle), [
        'intention_to_participate' => '1',
        'able_and_willing' => '1',
        // satisfy_preconditions deliberately omitted
        'no_impairment' => '1',
        'signature' => $user->name,
    ])->assertSessionHasErrors('satisfy_preconditions');

    expect(SelectionDeclaration::query()->count())->toBe(0);
});

it('rejects a form submission when the signature does not match the account name', function () {
    $cycle = makeShooterCycle();
    $user = makeShooterUser();
    $this->actingAs($user)->post(route('iprf.opt-in', $cycle));

    $this->actingAs($user)->post(route('iprf.form', $cycle), [
        'intention_to_participate' => '1',
        'able_and_willing' => '1',
        'satisfy_preconditions' => '1',
        'no_impairment' => '1',
        'signature' => 'Someone Else',
    ])->assertSessionHasErrors('signature');

    expect(SelectionDeclaration::query()->count())->toBe(0);
});

it('redirects to profile when citizenship or country of residence is missing', function () {
    $cycle = makeShooterCycle();
    $user = User::factory()->create([
        'name' => 'Incomplete Profile',
        'sa_citizen' => null,
        'country_of_residence' => null,
        'email_verified_at' => now(),
    ]);
    $user->assignRole('member');

    $this->actingAs($user)->post(route('iprf.form', $cycle), [
        'intention_to_participate' => '1',
        'able_and_willing' => '1',
        'satisfy_preconditions' => '1',
        'no_impairment' => '1',
        'signature' => $user->name,
    ])->assertRedirect(route('profile'));

    expect(SelectionDeclaration::query()->count())->toBe(0);
});

it('notifies developer / owner / exco users when a form is submitted', function () {
    $cycle = makeShooterCycle();
    $user = makeShooterUser();

    $exco = User::factory()->create(['email_verified_at' => now()]);
    $exco->assignRole('exco');
    $member = User::factory()->create(['email_verified_at' => now()]);
    $member->assignRole('member');

    $this->actingAs($user)->post(route('iprf.opt-in', $cycle));
    $this->actingAs($user)->post(route('iprf.form', $cycle), [
        'intention_to_participate' => '1',
        'able_and_willing' => '1',
        'satisfy_preconditions' => '1',
        'no_impairment' => '1',
        'signature' => $user->name,
    ]);

    Notification::assertSentTo([$exco], SelectionDeclarationSubmittedNotification::class);
    Notification::assertNotSentTo([$member], SelectionDeclarationSubmittedNotification::class);
});

it('lets a shooter withdraw and records the withdrawal in the audit log', function () {
    $cycle = makeShooterCycle();
    $user = makeShooterUser();
    $this->actingAs($user)->post(route('iprf.opt-in', $cycle));
    $this->actingAs($user)->post(route('iprf.form', $cycle), [
        'intention_to_participate' => '1',
        'able_and_willing' => '1',
        'satisfy_preconditions' => '1',
        'no_impairment' => '1',
        'signature' => $user->name,
    ]);

    $this->actingAs($user)
        ->post(route('iprf.withdraw', $cycle))
        ->assertRedirect(route('iprf.index'));

    $athlete = SelectionAthlete::forCycle($cycle->id)->where('user_id', $user->id)->firstOrFail();
    expect($athlete->fresh()->state)->toBe(SelectionAthlete::STATE_NOT_SELECTED)
        ->and($athlete->declaration->fresh()->status)->toBe(SelectionDeclaration::STATUS_WITHDRAWN);

    expect(AuditLog::where('action_type', 'selection_athlete_self_withdrew')
        ->where('entity_id', $athlete->id)
        ->count())->toBe(1);
});

it('rejects form submission on a closed cycle', function () {
    $cycle = makeShooterCycle('closed');
    $user = makeShooterUser();

    $this->actingAs($user)->post(route('iprf.form', $cycle), [
        'intention_to_participate' => '1',
        'able_and_willing' => '1',
        'satisfy_preconditions' => '1',
        'no_impairment' => '1',
        'signature' => $user->name,
    ])->assertForbidden();

    expect(SelectionDeclaration::query()->count())->toBe(0);
});

it('auto-registers the athlete when the form is submitted without a prior opt-in', function () {
    $cycle = makeShooterCycle();
    $user = makeShooterUser();

    $this->actingAs($user)->post(route('iprf.form', $cycle), [
        'intention_to_participate' => '1',
        'able_and_willing' => '1',
        'satisfy_preconditions' => '1',
        'no_impairment' => '1',
        'signature' => $user->name,
    ])->assertRedirect(route('iprf.index'));

    expect(SelectionAthlete::forCycle($cycle->id)->where('user_id', $user->id)->exists())->toBeTrue();
    expect(AuditLog::where('action_type', 'selection_athlete_self_opted_in')->count())->toBe(1);
    expect(AuditLog::where('action_type', 'selection_declaration_submitted_online')->count())->toBe(1);
});
