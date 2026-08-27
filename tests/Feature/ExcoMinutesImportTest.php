<?php

/**
 * Coverage for the "Bulk import minutes from JSON" flow. Confirms
 * that the importer:
 *
 *   • Populates every agenda item's minutes field.
 *   • Appends a Decisions block when decisions are supplied.
 *   • Creates real exco_actions rows linked to the meeting AND the
 *     matching agenda item.
 *   • Resolves owner names to users via the ExCo directory.
 *   • Preserves unmatched owner names in the action's details field.
 *   • Skips (with a reason) items whose title doesn't match, rather
 *     than overwriting the wrong row.
 *   • Refuses to run on a closed meeting.
 */

use App\Enums\ExcoActionStatus;
use App\Enums\ExcoAgendaItemVisibility;
use App\Enums\ExcoMeetingStatus;
use App\Enums\ExcoMeetingType;
use App\Models\ExcoAction;
use App\Models\ExcoAgendaItem;
use App\Models\ExcoMeeting;
use App\Models\User;

beforeEach(function () {
    seedRoles();
});

function importingExcoUser(string $name = 'Andries Lategan'): User
{
    $user = User::factory()->create(['email_verified_at' => now(), 'name' => $name]);
    $user->assignRole(['exco', 'member']);

    return $user->fresh();
}

function heldMeetingWithAgenda(User $creator, int $items = 3): ExcoMeeting
{
    $meeting = ExcoMeeting::create([
        'title' => 'ExCo — Sitting',
        'type' => ExcoMeetingType::Regular,
        'scheduled_at' => now(),
        'status' => ExcoMeetingStatus::Held,
        'created_by' => $creator->id,
    ]);

    for ($i = 1; $i <= $items; $i++) {
        ExcoAgendaItem::create([
            'meeting_id' => $meeting->id,
            'sort_order' => $i,
            'title' => match ($i) {
                1 => 'Welcome, attendance and apologies',
                2 => 'IPRF 2028 Worlds MOU',
                3 => 'Website proposal — match fees vs platform cost',
                default => "Item {$i}",
            },
            'visibility' => ExcoAgendaItemVisibility::Ordinary,
        ]);
    }

    return $meeting->fresh();
}

it('populates minutes on every agenda item from a full payload', function () {
    $exco = importingExcoUser();
    $meeting = heldMeetingWithAgenda($exco);

    $payload = json_encode([
        'items' => [
            [
                'index' => 1,
                'title' => 'Welcome, attendance and apologies',
                'minutes' => 'Attendance confirmed. Two apologies received.',
                'decisions' => [],
                'actions' => [],
            ],
            [
                'index' => 2,
                'title' => 'IPRF 2028 Worlds MOU',
                'minutes' => 'MOU signed as-is following discussion.',
                'decisions' => ['ExCo authorises the Chair to sign the MOU.'],
                'actions' => [],
            ],
            [
                'index' => 3,
                'title' => 'Website proposal — match fees vs platform cost',
                'minutes' => 'Cap agreed at R1,500.',
                'decisions' => [],
                'actions' => [],
            ],
        ],
    ]);

    $this->actingAs($exco)
        ->post(route('exco.meetings.minutes.import', $meeting), ['payload' => $payload])
        ->assertRedirect(route('exco.meetings.show', $meeting));

    $items = $meeting->agendaItems()->orderBy('sort_order')->get();
    expect($items[0]->minutes)->toBe('Attendance confirmed. Two apologies received.')
        ->and($items[1]->minutes)->toContain('MOU signed as-is')
        ->and($items[1]->minutes)->toContain('Decisions:')
        ->and($items[1]->minutes)->toContain('- ExCo authorises the Chair to sign the MOU.')
        ->and($items[2]->minutes)->toBe('Cap agreed at R1,500.');
});

it('creates action items linked to the meeting and agenda item', function () {
    $exco = importingExcoUser('Paul Charsley');
    $meeting = heldMeetingWithAgenda($exco, items: 1);

    $payload = json_encode([
        'items' => [
            [
                'index' => 1,
                'title' => 'Welcome, attendance and apologies',
                'minutes' => 'Standing opener.',
                'actions' => [
                    [
                        'title' => 'Circulate August minutes',
                        'owner' => 'Paul Charsley',
                        'due' => '2026-09-24',
                        'details' => 'Attach the signed PDF.',
                    ],
                ],
            ],
        ],
    ]);

    $this->actingAs($exco)
        ->post(route('exco.meetings.minutes.import', $meeting), ['payload' => $payload])
        ->assertRedirect();

    $action = ExcoAction::firstWhere('title', 'Circulate August minutes');
    $item = $meeting->agendaItems()->first();

    expect($action)->not->toBeNull()
        ->and($action->meeting_id)->toBe($meeting->id)
        ->and($action->agenda_item_id)->toBe($item->id)
        ->and($action->assigned_to)->toBe($exco->id)
        ->and($action->due_on->format('Y-m-d'))->toBe('2026-09-24')
        ->and($action->status)->toBe(ExcoActionStatus::Open)
        ->and($action->details)->toBe('Attach the signed PDF.');
});

it('preserves unmatched owner names in the action details', function () {
    $exco = importingExcoUser('Paul Charsley');
    $meeting = heldMeetingWithAgenda($exco, items: 1);

    $payload = json_encode([
        'items' => [
            [
                'index' => 1,
                'title' => 'Welcome, attendance and apologies',
                'minutes' => 'x',
                'actions' => [
                    [
                        'title' => 'Follow up with someone external',
                        'owner' => 'Jane Random',
                        'due' => 'next meeting',
                    ],
                ],
            ],
        ],
    ]);

    $this->actingAs($exco)
        ->post(route('exco.meetings.minutes.import', $meeting), ['payload' => $payload])
        ->assertRedirect();

    $action = ExcoAction::firstWhere('title', 'Follow up with someone external');
    expect($action)->not->toBeNull()
        ->and($action->assigned_to)->toBeNull()
        ->and($action->due_on)->toBeNull()
        ->and($action->details)->toContain('Jane Random');
});

it('resolves an owner name by partial match when unambiguous', function () {
    $exco = importingExcoUser('Andries Lategan');
    $meeting = heldMeetingWithAgenda($exco, items: 1);

    $payload = json_encode([
        'items' => [
            [
                'index' => 1,
                'title' => 'Welcome, attendance and apologies',
                'minutes' => 'x',
                'actions' => [
                    ['title' => 'Chair to email members', 'owner' => 'Andries'],
                ],
            ],
        ],
    ]);

    $this->actingAs($exco)
        ->post(route('exco.meetings.minutes.import', $meeting), ['payload' => $payload])
        ->assertRedirect();

    $action = ExcoAction::firstWhere('title', 'Chair to email members');
    expect($action->assigned_to)->toBe($exco->id);
});

it('skips items whose title does not match, without touching them', function () {
    $exco = importingExcoUser();
    $meeting = heldMeetingWithAgenda($exco, items: 2);

    $payload = json_encode([
        'items' => [
            [
                'index' => 1,
                'title' => 'Welcome, attendance and apologies',
                'minutes' => 'Standing opener.',
            ],
            [
                'index' => 2,
                'title' => 'Completely different item that was never on the agenda',
                'minutes' => 'This should not overwrite item 2.',
            ],
        ],
    ]);

    $response = $this->actingAs($exco)
        ->post(route('exco.meetings.minutes.import', $meeting), ['payload' => $payload]);

    $response->assertRedirect()
        ->assertSessionHas('minutes_import_skipped');

    $items = $meeting->agendaItems()->orderBy('sort_order')->get();
    expect($items[0]->minutes)->toBe('Standing opener.')
        ->and($items[1]->minutes)->toBeNull();
});

it('rejects an import onto a closed meeting', function () {
    $exco = importingExcoUser();
    $meeting = heldMeetingWithAgenda($exco, items: 1);
    $meeting->update(['status' => ExcoMeetingStatus::Closed]);

    $payload = json_encode([
        'items' => [
            ['index' => 1, 'title' => 'Welcome, attendance and apologies', 'minutes' => 'x'],
        ],
    ]);

    $this->actingAs($exco)
        ->post(route('exco.meetings.minutes.import', $meeting), ['payload' => $payload])
        ->assertRedirect();

    expect($meeting->agendaItems()->first()->minutes)->toBeNull();
});

it('rejects invalid JSON with a friendly error', function () {
    $exco = importingExcoUser();
    $meeting = heldMeetingWithAgenda($exco);

    $this->actingAs($exco)
        ->post(route('exco.meetings.minutes.import', $meeting), ['payload' => '{not json'])
        ->assertRedirect()
        ->assertSessionHasErrors('json');
});
