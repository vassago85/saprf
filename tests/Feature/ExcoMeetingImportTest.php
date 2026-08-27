<?php

/**
 * Coverage for the "Import meeting from JSON" flow. Ensures the
 * importer accepts a valid full-meeting payload, refuses malformed
 * JSON with a friendly error, and lets already-created meetings
 * append additional agenda items via the agenda-only shape.
 */

use App\Enums\ExcoAgendaItemVisibility;
use App\Enums\ExcoMeetingStatus;
use App\Enums\ExcoMeetingType;
use App\Models\ExcoMeeting;
use App\Models\User;

beforeEach(function () {
    seedRoles();
});

function importingExco(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole(['exco', 'member']);

    return $user->fresh();
}

it('renders the import form for exco', function () {
    $this->actingAs(importingExco())
        ->get(route('exco.meetings.import.form'))
        ->assertOk()
        ->assertSee('JSON payload');
});

it('blocks non-exco from the import form', function () {
    $member = User::factory()->create(['email_verified_at' => now()]);
    $member->assignRole('member');

    $this->actingAs($member->fresh())
        ->get(route('exco.meetings.import.form'))
        ->assertForbidden();
});

it('creates a meeting and agenda items from a full JSON payload', function () {
    $exco = importingExco();

    $payload = json_encode([
        'meeting' => [
            'title' => 'ExCo — September 2026',
            'type' => 'regular',
            'scheduled_at' => '2026-09-24 18:00',
            'location' => 'Zoom',
            'attendance_notes' => "MEMBERS\n- Andries\n- Paul",
        ],
        'agenda_items' => [
            ['title' => 'Welcome, attendance and apologies', 'briefing' => 'Responsible: AL'],
            ['title' => 'Website costs', 'briefing' => 'Decide cap.', 'visibility' => 'ordinary'],
            ['title' => 'HR matter', 'visibility' => 'confidential'],
        ],
    ]);

    $response = $this->actingAs($exco)->post(route('exco.meetings.import'), [
        'payload' => $payload,
    ]);

    $meeting = ExcoMeeting::firstWhere('title', 'ExCo — September 2026');

    $response->assertRedirect(route('exco.meetings.show', $meeting));
    expect($meeting)->not->toBeNull()
        ->and($meeting->type)->toBe(ExcoMeetingType::Regular)
        ->and($meeting->status)->toBe(ExcoMeetingStatus::Draft)
        ->and($meeting->created_by)->toBe($exco->id)
        ->and($meeting->location)->toBe('Zoom')
        ->and($meeting->attendance_notes)->toContain('Andries')
        ->and($meeting->agendaItems()->count())->toBe(3);

    $items = $meeting->agendaItems()->orderBy('sort_order')->get();
    expect($items[0]->title)->toBe('Welcome, attendance and apologies')
        ->and($items[0]->sort_order)->toBe(1)
        ->and($items[0]->briefing)->toBe('Responsible: AL')
        ->and($items[2]->visibility)->toBe(ExcoAgendaItemVisibility::Confidential);
});

it('rejects invalid JSON with a friendly error', function () {
    $exco = importingExco();

    $this->actingAs($exco)->post(route('exco.meetings.import'), [
        'payload' => '{ this is not valid json',
    ])
        ->assertRedirect()
        ->assertSessionHasErrors('json');

    expect(ExcoMeeting::count())->toBe(0);
});

it('rejects a payload missing meeting.title', function () {
    $exco = importingExco();

    $payload = json_encode([
        'meeting' => [
            'scheduled_at' => '2026-09-24 18:00',
        ],
        'agenda_items' => [
            ['title' => 'Something'],
        ],
    ]);

    $this->actingAs($exco)->post(route('exco.meetings.import'), ['payload' => $payload])
        ->assertRedirect()
        ->assertSessionHasErrors('meeting.title');

    expect(ExcoMeeting::count())->toBe(0);
});

it('rejects an agenda item missing a title', function () {
    $exco = importingExco();

    $payload = json_encode([
        'meeting' => [
            'title' => 'A meeting',
            'scheduled_at' => '2026-09-24 18:00',
        ],
        'agenda_items' => [
            ['briefing' => 'no title'],
        ],
    ]);

    $this->actingAs($exco)->post(route('exco.meetings.import'), ['payload' => $payload])
        ->assertRedirect()
        ->assertSessionHasErrors();
});

it('appends agenda items to an existing draft meeting', function () {
    $exco = importingExco();

    $meeting = ExcoMeeting::create([
        'title' => 'Existing meeting',
        'type' => ExcoMeetingType::Regular,
        'scheduled_at' => now()->addDay(),
        'status' => ExcoMeetingStatus::Draft,
        'created_by' => $exco->id,
    ]);

    $payload = json_encode([
        'agenda_items' => [
            ['title' => 'First'],
            ['title' => 'Second', 'briefing' => 'Some briefing'],
        ],
    ]);

    $this->actingAs($exco)->post(route('exco.meetings.agenda.import', $meeting), [
        'payload' => $payload,
    ])->assertRedirect(route('exco.meetings.show', $meeting));

    expect($meeting->agendaItems()->count())->toBe(2);

    // Second append preserves sort order for a total of 4.
    $payload2 = json_encode([
        'agenda_items' => [
            ['title' => 'Third'],
            ['title' => 'Fourth'],
        ],
    ]);

    $this->actingAs($exco)->post(route('exco.meetings.agenda.import', $meeting), [
        'payload' => $payload2,
    ])->assertRedirect();

    $items = $meeting->agendaItems()->orderBy('sort_order')->get();
    expect($items->count())->toBe(4)
        ->and($items->pluck('sort_order')->all())->toBe([1, 2, 3, 4])
        ->and($items->last()->title)->toBe('Fourth');
});

it('refuses agenda import on a closed meeting', function () {
    $exco = importingExco();

    $meeting = ExcoMeeting::create([
        'title' => 'Closed',
        'type' => ExcoMeetingType::Regular,
        'scheduled_at' => now()->subDay(),
        'status' => ExcoMeetingStatus::Closed,
        'created_by' => $exco->id,
    ]);

    $payload = json_encode(['agenda_items' => [['title' => 'Late']]]);

    $this->actingAs($exco)->post(route('exco.meetings.agenda.import', $meeting), [
        'payload' => $payload,
    ])->assertRedirect();

    expect($meeting->agendaItems()->count())->toBe(0);
});

it('renders the AI prompts reference page', function () {
    $this->actingAs(importingExco())
        ->get(route('exco.prompts'))
        ->assertOk()
        ->assertSee('Meeting notice → JSON')
        ->assertSee('Transcript → minutes JSON (bulk import)')
        ->assertSee('Transcript → prose minutes (manual paste)');
});

it('blocks the AI prompts page for non-exco', function () {
    $member = User::factory()->create(['email_verified_at' => now()]);
    $member->assignRole('member');

    $this->actingAs($member->fresh())
        ->get(route('exco.prompts'))
        ->assertForbidden();
});
