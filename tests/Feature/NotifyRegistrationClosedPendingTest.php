<?php

use App\Jobs\NotifyRegistrationClosedPendingJob;
use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Province;
use App\Models\User;
use App\Notifications\RegistrationClosedPendingMdNotification;
use App\Notifications\RegistrationClosedPendingShooterNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    seedRoles();

    $this->province = Province::firstOrCreate(
        ['name' => 'Gauteng'],
        ['abbreviation' => 'GP']
    );

    $this->md = User::factory()->create(['email_verified_at' => now()]);
    $this->md->assignRole('match_director');

    $this->open = Division::firstOrCreate(
        ['slug' => 'open'],
        ['name' => 'Open', 'display_order' => 1, 'is_active' => true]
    );
});

function closedMatch(User $md, Province $province, array $extra = []): MatchEvent
{
    return MatchEvent::create(array_merge([
        'name' => 'GP Provincial Close Test',
        'match_type' => 'PR22',
        'series' => 'PR22',
        'series_level' => 'provincial',
        'season' => '2026',
        'province_id' => $province->id,
        'match_date' => Carbon::today()->addWeek()->toDateString(),
        'registration_close_date' => Carbon::yesterday()->toDateString(),
        'status' => 'open',
        'published' => true,
        'created_by' => $md->id,
        'active_member_fee' => 100,
        'non_member_fee' => 150,
        'lapsed_member_fee' => 120,
    ], $extra));
}

function pendingEntry(MatchEvent $match, User $shooter, Division $division, array $extra = []): MatchRegistration
{
    return MatchRegistration::create(array_merge([
        'match_id' => $match->id,
        'user_id' => $shooter->id,
        'division_id' => $division->id,
        'shooter_name' => $shooter->name,
        'email' => $shooter->email,
        'membership_fee_category' => 'active_member',
        'fee_amount' => 100,
        'saprf_fee' => 50,
        'platform_fee' => 20,
        'gateway_fee' => 0,
        'md_net_amount' => 30,
        'payment_status' => 'unpaid',
        'registration_status' => 'pending',
        'registered_at' => now(),
    ], $extra));
}

it('emails the MD and each pending shooter when registration has closed', function () {
    Notification::fake();

    $match = closedMatch($this->md, $this->province);

    $jozef = User::factory()->create(['name' => 'Jozef Kriek', 'email_verified_at' => now()]);
    $paid = User::factory()->create(['name' => 'Paid Shooter', 'email_verified_at' => now()]);

    pendingEntry($match, $jozef, $this->open);
    pendingEntry($match, $paid, $this->open, [
        'registration_status' => 'confirmed',
        'payment_status' => 'paid',
    ]);

    (new NotifyRegistrationClosedPendingJob)->handle(app(\App\Services\AuditLogService::class));

    Notification::assertSentTo($jozef, RegistrationClosedPendingShooterNotification::class);
    Notification::assertNotSentTo($paid, RegistrationClosedPendingShooterNotification::class);
    Notification::assertSentTo($this->md, RegistrationClosedPendingMdNotification::class);

    expect($match->fresh()->registration_close_notified_at)->not->toBeNull();
});

it('is idempotent — does not re-notify after registration_close_notified_at is set', function () {
    Notification::fake();

    $match = closedMatch($this->md, $this->province, [
        'registration_close_notified_at' => now()->subHour(),
    ]);

    $shooter = User::factory()->create(['email_verified_at' => now()]);
    pendingEntry($match, $shooter, $this->open);

    (new NotifyRegistrationClosedPendingJob)->handle(app(\App\Services\AuditLogService::class));

    Notification::assertNothingSent();
});

it('does not notify while registration is still open', function () {
    Notification::fake();

    $match = closedMatch($this->md, $this->province, [
        'registration_close_date' => Carbon::tomorrow()->toDateString(),
    ]);

    $shooter = User::factory()->create(['email_verified_at' => now()]);
    pendingEntry($match, $shooter, $this->open);

    (new NotifyRegistrationClosedPendingJob)->handle(app(\App\Services\AuditLogService::class));

    Notification::assertNothingSent();
    expect($match->fresh()->registration_close_notified_at)->toBeNull();
});

it('offers pay and withdraw wording in the shooter email', function () {
    $match = closedMatch($this->md, $this->province);
    $shooter = User::factory()->create(['name' => 'Aliza Mey', 'email_verified_at' => now()]);
    $reg = pendingEntry($match, $shooter, $this->open);

    $mail = (new RegistrationClosedPendingShooterNotification($reg))->toMail($shooter);

    expect($mail->subject)->toContain('registration has closed')
        ->and($mail->actionUrl)->toBe(route('registrations.show', $reg));

    $rendered = implode("\n", [
        ...$mail->introLines,
        ...$mail->outroLines,
    ]);

    expect($rendered)->toContain('Pay now')
        ->and($rendered)->toContain('Withdraw');
});
