<?php

namespace App\Support;

use App\Enums\ExcoActionStatus;
use App\Models\ExcoAction;
use App\Models\ExcoAgendaItem;
use App\Models\ExcoMeeting;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Consumes the JSON payload produced by the transcript→minutes AI
 * prompt and applies it to a meeting in one pass:
 *
 *   • Each item's `minutes` overwrites that agenda item's minutes.
 *   • Each item's `decisions` are appended to the minutes text as a
 *     "Decisions:" bullet block (kept in the same field so the printed
 *     PDF reads naturally, and no schema churn).
 *   • Each item's `actions` become real exco_actions rows linked to
 *     both the meeting and the agenda item. Owner names are resolved
 *     against the ExCo directory (exact-then-partial match). If an
 *     owner can't be resolved the raw name is preserved in the
 *     action's details so no information is lost.
 *
 * The importer never fails silently: a summary array with counts and
 * per-item skip reasons comes back so the surrounding controller can
 * surface both wins and warnings on the redirect flash.
 *
 * Semantics:
 *   - Minutes are OVERWRITTEN on re-import (safe to re-run to fix a
 *     typo).
 *   - Actions are APPENDED on re-import (do NOT re-run without first
 *     removing the previous batch, or you get duplicates).
 */
final class ExcoMinutesImporter
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
     * Apply the parsed payload to the meeting.
     *
     * @return array{
     *   items_updated: int,
     *   items_skipped: list<array{index: int|string, reason: string}>,
     *   actions_created: int,
     *   actions_with_unmatched_owners: int,
     * }
     */
    public static function apply(ExcoMeeting $meeting, array $payload, int $createdBy): array
    {
        $data = self::validate($payload);

        $agendaItems = $meeting->agendaItems()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        // Small in-memory user directory for owner-name resolution.
        // We do NOT hit the DB per action.
        $excoUsers = User::query()
            ->role(['exco', 'chair', 'developer'])
            ->orderBy('name')
            ->get(['id', 'name']);

        $summary = [
            'items_updated' => 0,
            'items_skipped' => [],
            'actions_created' => 0,
            'actions_with_unmatched_owners' => 0,
        ];

        return DB::transaction(function () use ($data, $agendaItems, $excoUsers, $meeting, $createdBy, &$summary): array {
            foreach ($data['items'] as $payloadItem) {
                $rawIndex = $payloadItem['index'] ?? null;
                $index = is_numeric($rawIndex) ? (int) $rawIndex : null;

                if ($index === null || $index < 1) {
                    $summary['items_skipped'][] = [
                        'index' => $rawIndex ?? '?',
                        'reason' => 'Missing or invalid "index" (must be a 1-based integer).',
                    ];
                    continue;
                }

                /** @var ExcoAgendaItem|null $agendaItem */
                $agendaItem = $agendaItems[$index - 1] ?? null;

                if ($agendaItem === null) {
                    $summary['items_skipped'][] = [
                        'index' => $index,
                        'reason' => sprintf('No agenda item at index %d (meeting has %d items).', $index, $agendaItems->count()),
                    ];
                    continue;
                }

                $expectedTitle = trim((string) ($payloadItem['title'] ?? ''));

                if ($expectedTitle !== '' && ! self::titlesRoughlyMatch($expectedTitle, $agendaItem->title)) {
                    $summary['items_skipped'][] = [
                        'index' => $index,
                        'reason' => sprintf(
                            'Title mismatch: agenda has "%s", payload has "%s". Skipped to avoid overwriting the wrong item — check the AI didn\'t rename items.',
                            $agendaItem->title,
                            $expectedTitle,
                        ),
                    ];
                    continue;
                }

                $agendaItem->update([
                    'minutes' => self::composeMinutes(
                        (string) ($payloadItem['minutes'] ?? ''),
                        $payloadItem['decisions'] ?? [],
                    ),
                ]);
                $summary['items_updated']++;

                foreach ($payloadItem['actions'] ?? [] as $actionPayload) {
                    $result = self::createAction(
                        $meeting,
                        $agendaItem,
                        $actionPayload,
                        $excoUsers,
                        $createdBy,
                    );

                    $summary['actions_created']++;
                    if ($result === 'unmatched_owner') {
                        $summary['actions_with_unmatched_owners']++;
                    }
                }
            }

            return $summary;
        });
    }

    /**
     * Structural validation. `items` is required and must be a non-
     * empty list. Individual items only require an `index`; missing
     * `title`/`minutes` are handled at apply-time so a partial payload
     * still produces a helpful skip reason rather than a validation
     * failure.
     *
     * @return array<string, mixed>
     */
    private static function validate(array $payload): array
    {
        return Validator::make($payload, [
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.index' => ['required', 'integer', 'min:1'],
            'items.*.title' => ['nullable', 'string', 'max:200'],
            'items.*.minutes' => ['nullable', 'string', 'max:10000'],
            'items.*.decisions' => ['nullable', 'array', 'max:20'],
            'items.*.decisions.*' => ['nullable', 'string', 'max:1000'],
            'items.*.actions' => ['nullable', 'array', 'max:20'],
            'items.*.actions.*.title' => ['required_with:items.*.actions', 'string', 'max:200'],
            'items.*.actions.*.owner' => ['nullable', 'string', 'max:200'],
            'items.*.actions.*.due' => ['nullable', 'string', 'max:50'],
            'items.*.actions.*.details' => ['nullable', 'string', 'max:5000'],
        ])->validate();
    }

    /**
     * Compare AI-supplied title against the agenda title. Accepts
     * exact case-insensitive match, or a substring match after
     * stripping ordinary agenda-notice noise ("1. ", "3.1 ",
     * trailing "*", "(decision)"). Deliberately generous so a
     * "Website proposal — match fees vs platform cost" AI output
     * still lines up with a "Website proposal — match fees vs
     * platform cost*" agenda title.
     */
    private static function titlesRoughlyMatch(string $expected, string $actual): bool
    {
        $normalize = static function (string $s): string {
            $s = strtolower(trim($s));
            $s = preg_replace('/^\d+(\.\d+)*\.?\s+/u', '', $s) ?? $s;
            $s = preg_replace('/\s*\([^)]*\)\s*$/u', '', $s) ?? $s;
            $s = rtrim($s, "*  \t\n\r\0\x0B");

            return preg_replace('/\s+/u', ' ', $s) ?? $s;
        };

        $a = $normalize($expected);
        $b = $normalize($actual);

        if ($a === '' || $b === '') {
            return false;
        }

        return $a === $b || str_starts_with($b, $a) || str_starts_with($a, $b);
    }

    /**
     * @param  list<string>  $decisions
     */
    private static function composeMinutes(string $minutes, array $decisions): ?string
    {
        $minutes = trim($minutes);
        $decisions = array_values(array_filter(array_map('trim', $decisions), static fn ($d) => $d !== ''));

        if ($minutes === '' && $decisions === []) {
            return null;
        }

        if ($decisions === []) {
            return $minutes;
        }

        $decisionsBlock = "Decisions:\n" . implode("\n", array_map(static fn ($d) => "- $d", $decisions));

        if ($minutes === '') {
            return $decisionsBlock;
        }

        return $minutes . "\n\n" . $decisionsBlock;
    }

    /**
     * Create one action row from a payload action. Returns 'ok' on a
     * matched owner, 'unmatched_owner' when the string was non-empty
     * but couldn't be resolved (the raw name lands in details so the
     * information is preserved).
     */
    private static function createAction(
        ExcoMeeting $meeting,
        ExcoAgendaItem $agendaItem,
        array $payload,
        Collection $excoUsers,
        int $createdBy,
    ): string {
        $rawOwner = trim((string) ($payload['owner'] ?? ''));
        $ownerId = $rawOwner !== '' ? self::resolveOwnerId($rawOwner, $excoUsers) : null;
        $unmatched = $rawOwner !== '' && $ownerId === null;

        $details = trim((string) ($payload['details'] ?? ''));
        if ($unmatched) {
            $prefix = 'Owner (unmatched from import): ' . $rawOwner;
            $details = $details === '' ? $prefix : "$prefix\n\n$details";
        }

        ExcoAction::create([
            'meeting_id' => $meeting->id,
            'agenda_item_id' => $agendaItem->id,
            'title' => trim((string) $payload['title']),
            'details' => $details === '' ? null : $details,
            'assigned_to' => $ownerId,
            'due_on' => self::parseDueDate($payload['due'] ?? null),
            'status' => ExcoActionStatus::Open,
            'created_by' => $createdBy,
        ]);

        return $unmatched ? 'unmatched_owner' : 'ok';
    }

    /**
     * Resolve an owner name to a user id. Case-insensitive; tries an
     * exact match first, then falls back to a unique partial match
     * (the AI often shortens "Andries Lategan" to "Andries" or "AL").
     */
    private static function resolveOwnerId(string $name, Collection $excoUsers): ?int
    {
        $lower = strtolower($name);

        $exact = $excoUsers->first(fn (User $u) => strtolower($u->name) === $lower);
        if ($exact !== null) {
            return $exact->id;
        }

        $partial = $excoUsers->filter(
            fn (User $u) => str_contains(strtolower($u->name), $lower)
                || str_contains($lower, strtolower($u->name))
        );

        return $partial->count() === 1 ? $partial->first()->id : null;
    }

    /**
     * Accept YYYY-MM-DD. "next meeting" / "" / null all become null
     * (the platform doesn't have a "next meeting" special date and
     * we deliberately don't guess).
     */
    private static function parseDueDate(mixed $value): ?Carbon
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || strcasecmp($value, 'next meeting') === 0) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }
}
