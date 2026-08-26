<?php

namespace App\Support;

use App\Enums\ExcoAgendaItemVisibility;
use App\Enums\ExcoMeetingStatus;
use App\Enums\ExcoMeetingType;
use App\Models\ExcoAgendaItem;
use App\Models\ExcoMeeting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Parses and validates the JSON payload accepted by the ExCo meeting
 * importer, and persists the resulting rows.
 *
 * Two shapes are accepted:
 *
 *   Full meeting  (creates a meeting + agenda items in one transaction):
 *
 *       {
 *           "meeting":       { "title": "...", "type": "regular", ... },
 *           "agenda_items":  [ { "title": "...", "briefing": "...", "visibility": "ordinary" }, ... ]
 *       }
 *
 *   Agenda-only  (appends to an existing draft/held meeting):
 *
 *       {
 *           "agenda_items":  [ { "title": "..." }, ... ]
 *       }
 *
 * The importer throws ValidationException on any structural problem so
 * the surrounding controller can hand the errors back to Laravel's
 * ->withErrors() flow unchanged.
 */
final class ExcoMeetingImporter
{
    /**
     * Parse a raw JSON string. Throws ValidationException on invalid
     * JSON, or if the payload is not a JSON object.
     *
     * @return array<string, mixed>
     */
    public static function parse(string $raw): array
    {
        $decoded = json_decode(trim($raw), true);

        if (! is_array($decoded)) {
            throw ValidationException::withMessages([
                'json' => 'Could not parse JSON. Check for a stray comma or unquoted key.',
            ]);
        }

        return $decoded;
    }

    /**
     * Create a new meeting from a full import payload.
     */
    public static function createMeeting(array $payload, int $createdBy): ExcoMeeting
    {
        $data = self::validate($payload, requireMeeting: true);

        return DB::transaction(function () use ($data, $createdBy): ExcoMeeting {
            $meeting = ExcoMeeting::create([
                'title' => $data['meeting']['title'],
                'type' => $data['meeting']['type'] ?? ExcoMeetingType::Regular->value,
                'scheduled_at' => $data['meeting']['scheduled_at'],
                'location' => $data['meeting']['location'] ?? null,
                'attendance_notes' => $data['meeting']['attendance_notes'] ?? null,
                'status' => ExcoMeetingStatus::Draft,
                'created_by' => $createdBy,
            ]);

            self::attachAgendaItems($meeting, $data['agenda_items'] ?? []);

            return $meeting;
        });
    }

    /**
     * Append agenda items to an existing draft/held meeting.
     *
     * @return int Number of items inserted.
     */
    public static function appendAgenda(ExcoMeeting $meeting, array $payload): int
    {
        $data = self::validate($payload, requireMeeting: false);

        $items = $data['agenda_items'] ?? [];

        if ($items === []) {
            return 0;
        }

        return DB::transaction(function () use ($meeting, $items): int {
            return self::attachAgendaItems($meeting, $items);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private static function attachAgendaItems(ExcoMeeting $meeting, array $items): int
    {
        $nextOrder = ((int) $meeting->agendaItems()->max('sort_order')) + 1;
        $count = 0;

        foreach ($items as $item) {
            ExcoAgendaItem::create([
                'meeting_id' => $meeting->id,
                'sort_order' => $nextOrder++,
                'title' => $item['title'],
                'briefing' => $item['briefing'] ?? null,
                'visibility' => $item['visibility'] ?? ExcoAgendaItemVisibility::Ordinary->value,
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * Structural validation of the parsed payload.
     *
     * When `requireMeeting` is true, `meeting.*` fields must be present;
     * otherwise the meeting block is optional (agenda-only imports).
     * `agenda_items` is optional but, when present, must be an array of
     * shape-conformant items.
     *
     * @return array<string, mixed>
     */
    private static function validate(array $payload, bool $requireMeeting): array
    {
        $types = implode(',', array_column(ExcoMeetingType::cases(), 'value'));
        $visibilities = implode(',', array_column(ExcoAgendaItemVisibility::cases(), 'value'));

        $rules = [
            'agenda_items' => ['nullable', 'array', 'max:100'],
            'agenda_items.*.title' => ['required', 'string', 'max:200'],
            'agenda_items.*.briefing' => ['nullable', 'string', 'max:10000'],
            'agenda_items.*.visibility' => ['nullable', 'string', 'in:' . $visibilities],
        ];

        if ($requireMeeting) {
            $rules['meeting'] = ['required', 'array'];
            $rules['meeting.title'] = ['required', 'string', 'max:200'];
            $rules['meeting.type'] = ['nullable', 'string', 'in:' . $types];
            $rules['meeting.scheduled_at'] = ['required', 'date'];
            $rules['meeting.location'] = ['nullable', 'string', 'max:200'];
            $rules['meeting.attendance_notes'] = ['nullable', 'string', 'max:5000'];
        } else {
            $rules['meeting'] = ['nullable', 'array'];
            $rules['agenda_items'] = ['required', 'array', 'min:1', 'max:100'];
        }

        return Validator::make($payload, $rules)->validate();
    }
}
