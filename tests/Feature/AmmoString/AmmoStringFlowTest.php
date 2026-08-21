<?php

use App\Models\AmmoLoad;
use App\Models\AmmoString;
use App\Models\User;
use Livewire\Volt\Volt;

beforeEach(function () {
    seedRoles();
    $this->user = User::factory()->create();
    $this->user->assignRole('member');
});

it('creates a string, applies a paste, and computes stats', function () {
    $this->actingAs($this->user)
        ->post(route('ammo-strings.store'), [
            'label' => '25-round confirmation',
            'fired_on' => now()->toDateString(),
        ])
        ->assertRedirect();

    $string = AmmoString::forUser($this->user->id)->latest('id')->first();
    expect($string)->not->toBeNull();

    $paste = "2795\n2802\n2799\n2803\n2798\n2802\n2800\n2797\n2804\n2799";

    $component = Volt::actingAs($this->user)
        ->test('ammo-string.session', ['string' => $string])
        ->set('paste', $paste)
        ->call('applyPaste');

    $string->refresh()->load('shots');
    expect($string->shots)->toHaveCount(10);
    // Fire order preserved as sequence numbering.
    expect($string->shots->first()->sequence)->toBe(1);
    expect((float) $string->shots->first()->velocity_fps)->toEqual(2795.0);
    expect($string->shots->last()->sequence)->toBe(10);
});

it('drops implausible velocities and comment lines from the paste', function () {
    $string = AmmoString::factory()->for($this->user)->create();

    $paste = <<<'TXT'
# Range notes: sunny, 22°C, wind 3 o'clock
2795
2802
NA
1.5
50000
2798
TXT;

    Volt::actingAs($this->user)
        ->test('ammo-string.session', ['string' => $string])
        ->set('paste', $paste)
        ->call('applyPaste');

    $string->refresh()->load('shots');
    // 2795, 2802, 2798 survive; comment/NA/1.5/50000 rejected.
    expect($string->shots)->toHaveCount(3);
});

it('toggles a shot out of the analysis without deleting it', function () {
    $string = AmmoString::factory()->for($this->user)->create();
    $string->shots()->createMany([
        ['sequence' => 1, 'velocity_fps' => 2795.0, 'excluded' => false],
        ['sequence' => 2, 'velocity_fps' => 2802.0, 'excluded' => false],
        ['sequence' => 3, 'velocity_fps' => 2799.0, 'excluded' => false],
    ]);

    $shotId = $string->shots()->orderBy('sequence')->first()->id;

    Volt::actingAs($this->user)
        ->test('ammo-string.session', ['string' => $string])
        ->call('toggleShotExcluded', $shotId);

    $string->refresh()->load('shots');
    expect($string->shots)->toHaveCount(3);
    expect($string->shots->firstWhere('id', $shotId)->excluded)->toBeTrue();
});

it('writes the measured SD back to the linked ammo load when shots are saved', function () {
    $load = AmmoLoad::factory()->for($this->user)->create([
        'nickname' => 'H4350 40.8',
    ]);
    $string = AmmoString::factory()->for($this->user)->create([
        'ammo_load_id' => $load->id,
    ]);

    $paste = "2795\n2802\n2799\n2803\n2798\n2802\n2800\n2797\n2804\n2799";

    Volt::actingAs($this->user)
        ->test('ammo-string.session', ['string' => $string])
        ->set('paste', $paste)
        ->call('applyPaste');

    $load->refresh();
    expect($load->measured_sd_fps)->not->toBeNull();
    expect((float) $load->measured_sd_fps)->toBeGreaterThan(1.5);
    expect((float) $load->measured_sd_fps)->toBeLessThan(5.0);
    expect($load->measured_sd_n)->toBe(10);
    expect($load->measured_sd_string_id)->toBe($string->id);
});

it('exports the string as a CSV file', function () {
    $string = AmmoString::factory()->for($this->user)->create();
    foreach ([2795, 2802, 2799, 2803, 2798] as $i => $v) {
        $string->shots()->create([
            'sequence' => $i + 1,
            'velocity_fps' => $v,
            'excluded' => false,
        ]);
    }

    $response = $this->actingAs($this->user)
        ->get(route('ammo-strings.export.csv', $string));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');
    $csv = $response->getContent();
    expect($csv)->toContain('String,');
    expect($csv)->toContain('SD (fps)');
    expect($csv)->toContain('2795.0');
});
