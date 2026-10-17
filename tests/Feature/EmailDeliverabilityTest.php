<?php

use App\Models\EmailLog;
use App\Models\Membership;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\MembershipLapsedNotification;
use App\Notifications\ResetPasswordNotification;
use App\Services\SettingsService;
use App\Support\BackupNotificationAddress;
use Carbon\Carbon;
use Illuminate\Support\Facades\Config;

beforeEach(function () {
    seedRoles();
    $this->owner = User::factory()->create(['name' => 'Owner']);
    $this->owner->assignRole('owner');
});

it('sends failed-backup mail to the backup inbox and never to your@example.com', function () {
    Setting::updateOrCreate(
        ['key' => 'backup_notification_email'],
        ['value' => 'your@example.com', 'description' => 'test'],
    );
    app(SettingsService::class)->clearCache();

    BackupNotificationAddress::applyFromSettings();

    expect(config('backup.notifications.mail.to'))->toBe('paul@charsley.co.za')
        ->and(config('backup.notifications.notifications.'.\Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification::class))
        ->toBe(['mail'])
        ->and(config('backup.notifications.notifications.'.\Spatie\Backup\Notifications\Notifications\BackupWasSuccessfulNotification::class))
        ->not->toContain('mail')
        ->and(BackupNotificationAddress::isPlaceholder('your@example.com'))->toBeTrue();
});

it('defaults failed-backup mail to paul@charsley.co.za when settings are empty', function () {
    Setting::query()->where('key', 'backup_notification_email')->delete();
    config(['mail.from.address' => 'hello@example.com']);
    app(SettingsService::class)->clearCache();

    BackupNotificationAddress::applyFromSettings();

    expect(config('backup.notifications.mail.to'))->toBe('paul@charsley.co.za');
});

it('does not put the Mailgun API key in the site settings HTML', function () {
    Setting::updateOrCreate(
        ['key' => 'mailgun_domain'],
        ['value' => 'mg.saprf.co.za', 'description' => 'test'],
    );
    Setting::updateOrCreate(
        ['key' => 'mailgun_secret'],
        ['value' => 'key-super-secret-never-leak', 'description' => 'test'],
    );
    app(SettingsService::class)->clearCache();

    $html = $this->actingAs($this->owner)
        ->get(route('site-settings.index'))
        ->assertOk()
        ->assertSee('Key saved — leave blank to keep it')
        ->getContent();

    expect($html)->not->toContain('key-super-secret-never-leak');
});

it('rejects a placeholder backup address in site settings', function () {
    $this->actingAs($this->owner)
        ->from(route('site-settings.index'))
        ->put(route('site-settings.update'), emailDeliverabilitySettings([
            'backup_notification_email' => 'your@example.com',
        ]))
        ->assertSessionHasErrors('backup_notification_email');
});

it('shows the backup inbox on site settings', function () {
    $this->actingAs($this->owner)
        ->get(route('site-settings.index'))
        ->assertOk()
        ->assertSee('Backup notification email')
        ->assertSee('Failed backups only')
        ->assertSee('your@example.com is never used');
});

it('does not send a membership notice to a hard-bounced address', function () {
    Config::set('mail.default', 'array');
    $member = bouncedMember('Bounced Member');

    $member->notifyNow(new MembershipLapsedNotification($member->membership));

    expect(EmailLog::query()->where('to_email', $member->email)->count())->toBe(0);
});

it('still sends a password reset to a hard-bounced address', function () {
    Config::set('mail.default', 'array');
    $member = bouncedMember('Reset Member');

    $member->notifyNow(new ResetPasswordNotification('token'));

    expect(EmailLog::query()->where('to_email', $member->email)->count())->toBe(1);
});

it('sends a membership notice when the address has not bounced', function () {
    Config::set('mail.default', 'array');
    $member = bouncedMember('Clear Member');
    $member->forceFill(['email_bounced_at' => null, 'email_bounce_count' => 0])->save();

    $member->notifyNow(new MembershipLapsedNotification($member->membership));

    expect(EmailLog::query()->where('to_email', $member->email)->count())->toBe(1);
});

it('clears the bounce flag when the member changes their email', function () {
    $member = bouncedMember('Changed Email');

    $member->email = 'new-inbox@precisionrifle.co.za';
    $member->save();

    expect($member->fresh()->email_bounced_at)->toBeNull()
        ->and($member->fresh()->email_bounce_count)->toBe(0);
});

it('warns on the membership row when the email is bouncing', function () {
    $member = bouncedMember('Bouncing Row');

    $this->actingAs($this->owner)
        ->get(route('memberships.index', ['search' => 'Bouncing Row']))
        ->assertOk()
        ->assertSee('Bouncing Row')
        ->assertSee('Email bouncing');
});

it('explains sent versus delivered and calls closed rows Closed', function () {
    EmailLog::create([
        'to_email' => 'closed@precisionrifle.co.za',
        'subject' => 'Old queued notice',
        'notification_class' => MembershipLapsedNotification::class,
        'status' => EmailLog::STATUS_DISMISSED,
        'sent_at' => now(),
    ]);

    $this->actingAs($this->owner)
        ->get(route('email-logs.index'))
        ->assertOk()
        ->assertSee('not yet reported delivery')
        ->assertSee('Closed')
        ->assertSee('Membership Lapsed');

    expect($this->actingAs($this->owner)->get(route('email-logs.index'))->getContent())->not->toContain('>Complete<');
});

function emailDeliverabilitySettings(array $overrides = []): array
{
    return array_merge([
        'non_member_surcharge' => '250',
        'lapsed_member_surcharge' => '150',
        'withdrawal_admin_fee' => '100',
        'withdrawal_deadline_hours' => '72',
        'division_single_select' => '1',
        'saprf_fee_type' => 'fixed',
        'saprf_fee_value' => '50',
        'membership_platform_fee_pct' => '2.5',
        'estimated_gateway_fee_percentage' => '3.5',
        'estimated_gateway_flat_fee' => '2.00',
        'payfast_sandbox' => '1',
        'payments_enabled' => '1',
        'notifications_enabled' => '1',
        'mail_from_address' => 'noreply@saprf.co.za',
        'mail_from_name' => 'SAPRF',
        'owner_email' => 'owner@precisionrifle.co.za',
        'secretary_email' => 'secretary@precisionrifle.co.za',
    ], $overrides);
}

function bouncedMember(string $name): User
{
    $user = User::factory()->create(['name' => $name]);
    $user->forceFill([
        'email_bounced_at' => Carbon::now()->subDay(),
        'email_bounce_count' => 2,
    ])->save();
    $user->assignRole('member');

    Membership::create([
        'user_id' => $user->id,
        'saprf_number' => (string) (9100 + $user->id),
        'membership_type' => 'paid',
        'status' => 'lapsed',
        'payment_status' => 'paid',
        'start_date' => Carbon::today()->subYear(),
        'expiry_date' => Carbon::today()->subDays(10),
    ]);

    return $user->fresh('membership');
}
