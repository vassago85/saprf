<?php

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Province;
use App\Models\Score;
use App\Models\ScoreImport;
use App\Models\User;
use App\Notifications\PaymentInquiryNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    seedRoles();
    Storage::fake('local');
    Notification::fake();

    $this->province = Province::firstOrCreate(
        ['name' => 'Gauteng'],
        ['abbreviation' => 'GP']
    );

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    Division::firstOrCreate(['slug' => 'open'], ['name' => 'Open', 'display_order' => 1, 'is_active' => true]);

    $this->match = MatchEvent::create([
        'name' => 'CSV Assignment Match',
        'match_type' => 'PR22',
        'series' => 'PR22',
        'series_level' => 'provincial',
        'season' => '2026',
        'province_id' => $this->province->id,
        'match_date' => Carbon::yesterday()->toDateString(),
        'status' => 'completed',
        'published' => true,
        'created_by' => $this->admin->id,
        'active_member_fee' => 300,
        'non_member_fee' => 300,
        'lapsed_member_fee' => 300,
    ]);

    $this->shooter = User::factory()->create([
        'name' => 'Glen Clark',
        'email' => 'glen@example.com',
    ]);
    $this->shooter->assignRole('member');

    $this->registration = MatchRegistration::create([
        'match_id' => $this->match->id,
        'user_id' => $this->shooter->id,
        'shooter_name' => 'Glen Clark',
        'membership_fee_category' => 'active_member',
        'fee_amount' => 300,
        'saprf_fee' => 15,
        'platform_fee' => 15,
        'surcharge_amount' => 0,
        'gateway_fee' => 10,
        'md_net_amount' => 260,
        'payment_status' => 'pending',
        'registration_status' => 'pending',
        'registered_at' => now()->subWeek(),
    ]);
});

function assignmentCsv(string $body): \Illuminate\Http\UploadedFile
{
    $header = "Place,Last,First,Class,Div,Category,Member Number,Time,Impacts,Match %\n";

    return \Illuminate\Http\UploadedFile::fake()->createWithContent('scores.csv', $header.$body);
}

it('previews csv rows against match entries the same way impact does', function () {
    $body = "1,Clark,Glen,,Open,,,100,80,50%\n"
        ."2,Clarke,Glen,,Open,,,90,70,40%\n";

    $response = $this->actingAs($this->admin)
        ->withHeaders(['Accept' => 'application/json'])
        ->post(route('score-imports.csv.preview'), [
            'match_id' => $this->match->id,
            'file' => assignmentCsv($body),
        ]);

    $response->assertOk();
    $response->assertJsonPath('ok', true);
    $response->assertJsonPath('preview.summary.exact', 1);
    $response->assertJsonPath('preview.summary.fuzzy', 1);
    $response->assertJsonPath('preview.shooters.0.registration_id', $this->registration->id);
    $response->assertJsonPath('preview.shooters.0.action', 'import_and_collect');
});

it('assigns an exact csv score to the unpaid entry and holds a fuzzy row until confirmed', function () {
    $body = "1,Clark,Glen,,Open,,,100,80,50%\n"
        ."2,Nobody,Special,,Open,,,90,40,20%\n";

    $this->actingAs($this->admin)
        ->post(route('score-imports.store'), [
            'match_id' => $this->match->id,
            'source_type' => 'csv',
            'file' => assignmentCsv($body),
            'replace_existing' => 0,
            'assignment_review' => 1,
            'links' => [
                1 => 'assign_later',
            ],
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $glen = Score::where('match_id', $this->match->id)->where('shooter_name', 'Glen Clark')->first();
    $held = Score::where('match_id', $this->match->id)->where('shooter_name', 'Special Nobody')->first();

    expect($glen)->not->toBeNull()
        ->and($glen->user_id)->toBe($this->shooter->id)
        ->and((float) $glen->raw_score)->toBe(80.0);

    expect($held)->not->toBeNull()
        ->and($held->raw_meta['impact']['needs_member_link'])->toBeTrue()
        ->and($held->status)->toBe('invalid');

    $import = ScoreImport::query()->latest('id')->first();
    $reasons = collect($import->raw_payload['audit'])->pluck('reason');
    expect($reasons)->toContain('pending_unpaid')
        ->and($reasons)->toContain('assign_later');

    $this->actingAs($this->admin)
        ->get(route('score-imports.show', $import))
        ->assertOk()
        ->assertSee('Email unpaid shooters now')
        ->assertSee('Email Glen Clark');
});

it('refuses a reviewed csv import while a fuzzy name is still unconfirmed', function () {
    $body = "1,Clarke,Glen,,Open,,,90,70,40%\n";

    $this->actingAs($this->admin)
        ->from(route('score-imports.create'))
        ->post(route('score-imports.store'), [
            'match_id' => $this->match->id,
            'source_type' => 'csv',
            'file' => assignmentCsv($body),
            'assignment_review' => 1,
        ])
        ->assertRedirect(route('score-imports.create'))
        ->assertSessionHas('error');

    expect(Score::where('match_id', $this->match->id)->count())->toBe(0);
});

it('emails unpaid shooters from the import audit and from the match', function () {
    $body = "1,Clark,Glen,,Open,,,100,80,50%\n";

    $this->actingAs($this->admin)
        ->post(route('score-imports.store'), [
            'match_id' => $this->match->id,
            'source_type' => 'csv',
            'file' => assignmentCsv($body),
            'assignment_review' => 1,
        ])
        ->assertRedirect();

    $import = ScoreImport::query()->latest('id')->first();

    $this->actingAs($this->admin)
        ->get(route('matches.entries', $this->match))
        ->assertOk()
        ->assertSee('Email unpaid')
        ->assertSee(route('registrations.payment-inquiry', $this->registration), false);

    $this->actingAs($this->admin)
        ->get(route('matches.scores', $this->match))
        ->assertOk()
        ->assertSee('Email unpaid shooters')
        ->assertSee('Upload CSV');

    $this->actingAs($this->admin)
        ->get(route('score-imports.create', ['match_id' => $this->match->id, 'mode' => 'csv']))
        ->assertOk()
        ->assertSee('Preview & assign', false);

    $this->actingAs($this->admin)
        ->post(route('score-imports.notify-unpaid', $import))
        ->assertRedirect()
        ->assertSessionHas('success');

    Notification::assertSentTo($this->shooter, PaymentInquiryNotification::class);
    expect($this->registration->fresh()->payment_inquiry_sent_at)->not->toBeNull();

    Notification::fake();
    $this->registration->update(['payment_inquiry_sent_at' => now()->subDays(2)]);

    $this->actingAs($this->admin)
        ->post(route('matches.notify-unpaid', $this->match))
        ->assertRedirect()
        ->assertSessionHas('success');

    Notification::assertSentTo($this->shooter, PaymentInquiryNotification::class);
});
