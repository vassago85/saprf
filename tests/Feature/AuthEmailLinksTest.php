<?php

use App\Models\User;
use App\Notifications\EmailOtpNotification;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    seedRoles();
});

it('verifies email via signed link without an existing session (any device)', function () {
    $user = User::factory()->create([
        'email_verified_at' => null,
        'email_otp' => '123456',
        'email_otp_expires_at' => now()->addMinutes(30),
    ]);
    $user->assignRole('member');

    // Simulate opening the email on a different device — no prior auth cookie.
    expect(auth()->check())->toBeFalse();

    $url = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        [
            'id' => $user->id,
            'hash' => sha1($user->getEmailForVerification()),
        ],
    );

    $this->get($url)
        ->assertRedirect(route('dashboard'));

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue()
        ->and($user->fresh()->email_otp)->toBeNull()
        ->and(auth()->id())->toBe($user->id);
});

it('rejects a tampered verification link', function () {
    $user = User::factory()->create(['email_verified_at' => null]);
    $user->assignRole('member');

    $url = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        [
            'id' => $user->id,
            'hash' => sha1('wrong@example.com'),
        ],
    );

    $this->get($url)->assertForbidden();

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('includes a clickable absolute verification URL in the OTP email', function () {
    Notification::fake();

    $user = User::factory()->create(['email_verified_at' => null]);
    $otp = $user->generateEmailOtp();
    $user->notify(new EmailOtpNotification($otp));

    Notification::assertSentTo($user, EmailOtpNotification::class, function (EmailOtpNotification $notification) use ($user) {
        $mail = $notification->toMail($user);
        $actionUrl = $mail->actionUrl;

        expect($actionUrl)->not->toBeEmpty()
            ->and($actionUrl)->toContain('/email/verify/')
            ->and($actionUrl)->toContain((string) $user->id)
            ->and(parse_url($actionUrl, PHP_URL_SCHEME))->not->toBeNull();

        return true;
    });
});

it('sends a password reset email with an absolute link containing the email query', function () {
    Notification::fake();

    $user = User::factory()->create(['email_verified_at' => now()]);

    Password::sendResetLink(['email' => $user->email]);

    Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $notification) use ($user) {
        $mail = $notification->toMail($user);
        $actionUrl = $mail->actionUrl;

        expect($actionUrl)->toContain('/reset-password/')
            ->and($actionUrl)->toContain('email='.urlencode($user->email))
            ->and(parse_url($actionUrl, PHP_URL_SCHEME))->not->toBeNull()
            ->and(parse_url($actionUrl, PHP_URL_HOST))->not->toBeNull();

        return true;
    });
});

it('lets a guest open the password reset page from the emailed link', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $token = Password::createToken($user);

    $this->get(route('password.reset', [
        'token' => $token,
        'email' => $user->email,
    ]))->assertOk();
});
