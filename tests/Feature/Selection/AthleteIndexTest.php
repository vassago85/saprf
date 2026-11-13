<?php

use App\Models\AuditLog;
use App\Models\Club;
use App\Models\Membership;
use App\Models\Province;
use App\Models\SelectionAthlete;
use App\Models\SelectionCycle;
use App\Models\SelectionDeclaration;
use App\Models\SelectionParticipationSnapshot;
use App\Models\User;
use App\Notifications\SelectionDeclarationReminderNotification;
use App\Services\Selection\PolicyImportService;
use Illuminate\Support\Facades\Notification;

beforeEach(fn () => seedRoles());

function athleteIndexCycle(): SelectionCycle
{
    $cycle = SelectionCycle::create([
        'series' => 'PR22',
        'season' => '2027',
        'championship_name' => 'IPRF PR22 Team World Championships 2027',
        'qualifying_period_start' => '2026-01-01',
        'qualifying_period_end' => '2026-12-31',
        'declaration_deadline' => '2026-11-30 23:59:00',
        'results_freeze' => '2026-12-31',
        'status' => 'open',
        'evaluation_mode' => SelectionCycle::MODE_STRICT,
    ]);

    app(PolicyImportService::class)->import(
        base_path('docs/selection/pr22/2027/policy.json'),
        $cycle,
    );

    return $cycle->fresh();
}

function athleteIndexOwner(): User
{
    $owner = User::factory()->create();
    $owner->assignRole('owner');

    return $owner;
}

/**
 * Paid-up SA resident in a recognised club. ELG-01..04 pass. Participation
 * counts meet every PART rule when a full snapshot is attached.
 */
function athleteIndexShooter(SelectionCycle $cycle, string $name, array $userOverrides = []): SelectionAthlete
{
    $province = Province::firstOrCreate(['abbreviation' => 'GP'], ['name' => 'Gauteng']);
    $club = Club::create([
        'name' => 'Index Club '.uniqid(),
        'slug' => 'index-club-'.uniqid(),
        'province_id' => $province->id,
        'saprf_recognised' => true,
    ]);

    $user = User::factory()->create(array_merge([
        'name' => $name,
        'province_id' => $province->id,
        'club_id' => $club->id,
        'sa_citizen' => true,
        'country_of_residence' => 'ZA',
    ], $userOverrides));

    Membership::create([
        'user_id' => $user->id,
        'membership_type' => 'paid',
        'status' => 'active',
        'payment_status' => 'paid',
        'start_date' => '2025-01-01',
        'expiry_date' => '2027-12-31',
    ]);

    return SelectionAthlete::create([
        'selection_cycle_id' => $cycle->id,
        'user_id' => $user->id,
        'state' => SelectionAthlete::STATE_ELIGIBLE,
    ]);
}

function athleteIndexFullSnapshot(SelectionAthlete $athlete): void
{
    SelectionParticipationSnapshot::create([
        'selection_athlete_id' => $athlete->id,
        'provincial_1d_count' => 3,
        'national_2d_count' => 2,
        'international_2d_count' => 0,
        'out_of_home_province_2d_count' => 1,
        'sa_champs_shot' => true,
        'computed_at' => now(),
    ]);
}

it('searches by name and orders the registry by criteria met', function () {
    $cycle = athleteIndexCycle();
    $owner = athleteIndexOwner();

    $complete = athleteIndexShooter($cycle, 'Zoe Complete');
    athleteIndexFullSnapshot($complete);
    SelectionDeclaration::create([
        'selection_athlete_id' => $complete->id,
        'submitted_at' => now(),
        'status' => SelectionDeclaration::STATUS_SUBMITTED,
        'form_data' => ['eligibility_to_compete_received' => true],
    ]);

    $almost = athleteIndexShooter($cycle, 'Amy Almost');
    athleteIndexFullSnapshot($almost);

    $short = athleteIndexShooter($cycle, 'Ben Short');

    $this->actingAs($owner)
        ->get(route('selection.cycles.athletes.index', $cycle))
        ->assertOk()
        ->assertSee('Email declaration reminders')
        ->assertSee('name="q"', false)
        ->assertSeeInOrder(['Zoe Complete', 'Amy Almost', 'Ben Short']);

    $this->actingAs($owner)
        ->get(route('selection.cycles.athletes.index', [$cycle, 'sort' => 'criteria_asc']))
        ->assertOk()
        ->assertSeeInOrder(['Ben Short', 'Amy Almost', 'Zoe Complete']);

    $this->actingAs($owner)
        ->get(route('selection.cycles.athletes.index', [$cycle, 'sort' => 'name']))
        ->assertOk()
        ->assertSeeInOrder(['Amy Almost', 'Ben Short', 'Zoe Complete']);

    $this->actingAs($owner)
        ->get(route('selection.cycles.athletes.index', [$cycle, 'q' => 'amy']))
        ->assertOk()
        ->assertSee('Amy Almost')
        ->assertDontSee('Zoe Complete')
        ->assertDontSee('Ben Short');

    // An unrelated account must not satisfy the exists() check for every row.
    User::factory()->create(['email' => 'amy-stranger@example.com', 'name' => 'Not An Athlete']);

    $this->actingAs($owner)
        ->get(route('selection.cycles.athletes.index', [$cycle, 'q' => 'amy-stranger']))
        ->assertOk()
        ->assertDontSee('Amy Almost')
        ->assertDontSee('Zoe Complete')
        ->assertDontSee('Ben Short');
});

it('emails only shooters who are missing the declaration and nothing else', function () {
    Notification::fake();

    $cycle = athleteIndexCycle();
    $owner = athleteIndexOwner();

    $almost = athleteIndexShooter($cycle, 'Amy Almost');
    athleteIndexFullSnapshot($almost);

    $notCitizen = athleteIndexShooter($cycle, 'Ben Short', ['sa_citizen' => false]);
    athleteIndexFullSnapshot($notCitizen);

    $complete = athleteIndexShooter($cycle, 'Zoe Complete');
    athleteIndexFullSnapshot($complete);
    SelectionDeclaration::create([
        'selection_athlete_id' => $complete->id,
        'submitted_at' => now(),
        'status' => SelectionDeclaration::STATUS_SUBMITTED,
        'form_data' => ['eligibility_to_compete_received' => true],
    ]);

    $this->actingAs($owner)
        ->post(route('selection.cycles.athletes.declaration-reminders', $cycle))
        ->assertRedirect(route('selection.cycles.athletes.index', $cycle))
        ->assertSessionHas('success', 'Queued a declaration reminder for 1 shooter.');

    Notification::assertSentTo(
        $almost->user,
        SelectionDeclarationReminderNotification::class,
        function (SelectionDeclarationReminderNotification $notification) use ($almost, $cycle) {
            $mail = $notification->toMail($almost->user);

            return str_contains($mail->actionUrl, '/iprf#declaration-'.$cycle->id)
                && str_contains($mail->subject, 'PR22 2027');
        },
    );
    Notification::assertNotSentTo($notCitizen->user, SelectionDeclarationReminderNotification::class);
    Notification::assertNotSentTo($complete->user, SelectionDeclarationReminderNotification::class);
    Notification::assertCount(1);

    expect(AuditLog::where('action_type', 'selection_declaration_reminders_sent')
        ->where('entity_id', $cycle->id)
        ->count())->toBe(1);
});

it('tells the admin when nobody is waiting only on the declaration', function () {
    Notification::fake();

    $cycle = athleteIndexCycle();
    $owner = athleteIndexOwner();
    athleteIndexShooter($cycle, 'Ben Short');

    $this->actingAs($owner)
        ->post(route('selection.cycles.athletes.declaration-reminders', $cycle))
        ->assertRedirect()
        ->assertSessionHas('success', 'No shooters are waiting only on the declaration.');

    Notification::assertNothingSent();
});

it('does not email declaration reminders once the cycle is closed', function () {
    Notification::fake();

    $cycle = athleteIndexCycle();
    $cycle->update(['status' => 'frozen']);
    $owner = athleteIndexOwner();

    $almost = athleteIndexShooter($cycle, 'Amy Almost');
    athleteIndexFullSnapshot($almost);

    $this->actingAs($owner)
        ->post(route('selection.cycles.athletes.declaration-reminders', $cycle))
        ->assertRedirect()
        ->assertSessionHas('error', 'This cycle is not open, so shooters cannot submit the declaration. No reminders were sent.');

    Notification::assertNothingSent();
});

it('skips a declaration reminder when the shooter address has bounced', function () {
    Notification::fake();

    $cycle = athleteIndexCycle();
    $owner = athleteIndexOwner();

    $almost = athleteIndexShooter($cycle, 'Amy Almost');
    athleteIndexFullSnapshot($almost);
    $almost->user->forceFill(['email_bounced_at' => now()])->save();

    $this->actingAs($owner)
        ->post(route('selection.cycles.athletes.declaration-reminders', $cycle))
        ->assertRedirect()
        ->assertSessionHas('success', '1 shooter is only missing the declaration, but their email address has bounced or was marked as spam.');

    Notification::assertNothingSent();
});

it('refuses declaration reminders from a member', function () {
    $cycle = athleteIndexCycle();
    $member = User::factory()->create();
    $member->assignRole('member');

    $this->actingAs($member)
        ->post(route('selection.cycles.athletes.declaration-reminders', $cycle))
        ->assertForbidden();
});
