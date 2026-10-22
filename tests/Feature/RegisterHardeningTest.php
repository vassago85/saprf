<?php

use App\Models\Province;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Volt\Volt;

beforeEach(function () {
    seedRoles();
    Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);
    RateLimiter::clear('register:127.0.0.1');
});

/**
 * Return a fully-valid Volt test instance primed with the state that a real
 * sign-up would produce. Individual tests then tweak one axis (honeypot
 * filled, timestamp too fresh, missing field, …) rather than re-listing
 * the whole payload.
 *
 * SA ID numbers must be unique in `users.sa_id_number`; tests that would
 * otherwise collide pass their own via $overrides.
 */
function validRegistration(array $overrides = [])
{
    $province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);

    $defaults = [
        'name' => 'Alice Shooter',
        'email' => 'alice.hardening@example.com',
        'id_type' => 'sa_id',
        'sa_id_number' => '9001015800080',
        'province_id' => $province->id,
        'password' => 'SuperSecret123',
        'password_confirmation' => 'SuperSecret123',
        'terms_accepted' => true,
        // Pretend the form was opened five minutes ago so the min-fill-time
        // check passes by default. Individual tests override this.
        'hp_started_at' => now()->subMinutes(5)->getTimestamp(),
        'hp_field' => '',
    ];

    $state = array_merge($defaults, $overrides);

    $component = Volt::test('pages.auth.register');
    foreach ($state as $key => $value) {
        $component->set($key, $value);
    }

    return $component;
}

test('the honeypot silently blocks the sign-up without creating a user', function () {
    validRegistration([
        'email' => 'honeypot.spam@example.com',
        'sa_id_number' => '9001015800081',
        'hp_field' => 'https://cheap-viagra.example.com',
    ])
        ->call('register')
        ->assertHasNoErrors()
        // Silent success: same redirect a real sign-up gets, so bots can't
        // tell their trick failed.
        ->assertRedirect(route('verification.notice'));

    expect(User::where('email', 'honeypot.spam@example.com')->exists())->toBeFalse();
});

test('a sign-up submitted faster than the min fill time is silently dropped', function () {
    validRegistration([
        'email' => 'too.fast@example.com',
        'sa_id_number' => '9001015800082',
        // Form was "opened" the same second it was submitted.
        'hp_started_at' => now()->getTimestamp(),
    ])
        ->call('register')
        ->assertHasNoErrors()
        ->assertRedirect(route('verification.notice'));

    expect(User::where('email', 'too.fast@example.com')->exists())->toBeFalse();
});

test('a legitimate sign-up (hp empty + sensible fill time) still creates a user', function () {
    validRegistration([
        'email' => 'real.human@example.com',
        'sa_id_number' => '9001015800083',
    ])
        ->call('register')
        ->assertHasNoErrors()
        ->assertRedirect(route('verification.notice'));

    expect(User::where('email', 'real.human@example.com')->exists())->toBeTrue();
});

test('the per-IP throttle locks further sign-ups after 5 attempts', function () {
    // Burn the full rate-limit allowance with valid sign-ups.
    for ($i = 0; $i < 5; $i++) {
        $email = "burner{$i}@example.com";
        // SA IDs starting 90..00 keep uniqueness simple; the exact value
        // doesn't matter, only that each is distinct.
        $saId = '900101580' . str_pad((string) (90 + $i), 4, '0', STR_PAD_LEFT);

        validRegistration([
            'email' => $email,
            'sa_id_number' => $saId,
        ])
            ->call('register')
            ->assertRedirect(route('verification.notice'));
    }

    expect(User::count())->toBe(5);

    // The 6th attempt from the same IP is throttled, surfaced as a form
    // error, and never persists.
    validRegistration([
        'email' => 'blocked@example.com',
        'sa_id_number' => '9001015800095',
    ])
        ->call('register')
        ->assertHasErrors(['email']);

    expect(User::where('email', 'blocked@example.com')->exists())->toBeFalse();
    expect(User::count())->toBe(5);
});

test('the honeypot path does not count against the rate limit', function () {
    // Trip the honeypot ten times — bots that stumble into the trap
    // shouldn't be able to lock the IP out for real users on the same
    // network (shared office / club NAT).
    for ($i = 0; $i < 10; $i++) {
        validRegistration([
            'email' => "bot{$i}@example.com",
            'sa_id_number' => '900101580' . str_pad((string) (10 + $i), 4, '0', STR_PAD_LEFT),
            'hp_field' => 'bot filled this',
        ])
            ->call('register')
            ->assertRedirect(route('verification.notice'));
    }

    // A real sign-up still works afterwards.
    validRegistration([
        'email' => 'genuine@example.com',
        'sa_id_number' => '9001015800099',
    ])
        ->call('register')
        ->assertHasNoErrors()
        ->assertRedirect(route('verification.notice'));

    expect(User::where('email', 'genuine@example.com')->exists())->toBeTrue();
});
