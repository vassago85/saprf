<?php

use App\Models\Setting;
use App\Models\User;
use App\Notifications\ContactMessageReceivedNotification;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    seedRoles();
    Notification::fake();
    RateLimiter::clear('contact-form:127.0.0.1');
});

function setStaffInbox(string $key, string $value): void
{
    Setting::updateOrCreate(
        ['key' => $key],
        ['value' => $value, 'description' => 'test'],
    );
    app(SettingsService::class)->clearCache();
}

function validSiteSettings(array $overrides = []): array
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
        'exco_email' => 'exco@precisionrifle.co.za',
        'owner_email' => 'owner@precisionrifle.co.za',
        'secretary_email' => 'secretary@precisionrifle.co.za',
    ], $overrides);
}

it('lets an owner save the ExCo, secretary, and owner inbox addresses', function () {
    $owner = User::factory()->create();
    $owner->assignRole('owner');

    $this->actingAs($owner)
        ->put(route('site-settings.update'), validSiteSettings())
        ->assertRedirect(route('site-settings.index'))
        ->assertSessionHas('success');

    expect(app(SettingsService::class)->excoEmail())->toBe('exco@precisionrifle.co.za')
        ->and(app(SettingsService::class)->ownerEmail())->toBe('owner@precisionrifle.co.za')
        ->and(app(SettingsService::class)->secretaryEmail())->toBe('secretary@precisionrifle.co.za')
        ->and(app(SettingsService::class)->replyToEmail())->toBe('secretary@precisionrifle.co.za');
});

it('does not use the ExCo forwarder as Reply-To when the secretary inbox is empty', function () {
    setStaffInbox('exco_email', 'admin@precisionrifle.co.za');
    setStaffInbox('owner_email', 'owner-inbox@precisionrifle.co.za');
    setStaffInbox('secretary_email', '');

    expect(app(SettingsService::class)->replyToEmail())->toBeNull();
});

it('shows the ExCo, secretary, and owner inbox fields on site settings', function () {
    $owner = User::factory()->create();
    $owner->assignRole('owner');

    $this->actingAs($owner)
        ->get(route('site-settings.index'))
        ->assertOk()
        ->assertSee('ExCo Email')
        ->assertSee('Secretary Email')
        ->assertSee('Owner Email');
});

it('sends contact-form mail to the secretary inbox, not the owner or a shared admin address', function () {
    setStaffInbox('secretary_email', 'secretary-inbox@precisionrifle.co.za');
    setStaffInbox('owner_email', 'owner-inbox@precisionrifle.co.za');
    setStaffInbox('exco_email', 'exco-inbox@precisionrifle.co.za');

    $admin = User::factory()->create(['email' => 'admin@precisionrifle.co.za']);
    $admin->assignRole('admin');

    $this->post(route('contact.store'), [
        'first_name' => 'Alice',
        'surname' => 'Shooter',
        'email' => 'alice@example.com',
        'email_confirmation' => 'alice@example.com',
        'subject' => 'Question about SAPRF membership',
        'message' => 'I would like to know how to renew my SAPRF membership.',
        'hp_field' => '',
        'hp_started_at' => (string) (now()->subMinutes(2)->getTimestamp()),
    ])->assertRedirect(route('contact.thanks'));

    Notification::assertNotSentTo([$admin], ContactMessageReceivedNotification::class);

    Notification::assertSentOnDemand(ContactMessageReceivedNotification::class, function ($notification, $channels, $notifiable) {
        return $notifiable->routes['mail'] === 'secretary-inbox@precisionrifle.co.za';
    });

    Notification::assertSentOnDemandTimes(ContactMessageReceivedNotification::class, 1);
});

it('still notifies role users for contact forms when no dedicated inbox is set', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->post(route('contact.store'), [
        'first_name' => 'Alice',
        'surname' => 'Shooter',
        'email' => 'alice@example.com',
        'email_confirmation' => 'alice@example.com',
        'subject' => 'Question about SAPRF membership',
        'message' => 'I would like to know how to renew my SAPRF membership.',
        'hp_field' => '',
        'hp_started_at' => (string) (now()->subMinutes(2)->getTimestamp()),
    ])->assertRedirect(route('contact.thanks'));

    Notification::assertSentTo([$admin], ContactMessageReceivedNotification::class);
});
