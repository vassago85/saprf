<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Copies every historical row in `match_announcements` into the unified
 * `announcements` + `announcement_audiences` tables so the admin audit
 * view (and any future "all messages this MD has ever sent" report) can
 * work off one source of truth.
 *
 * Deliberate design choices:
 *
 *   - We do NOT create `announcement_recipients` rows. The old MD
 *     channel was email-only; members never had these in their inbox
 *     before. Backfilling recipient rows would suddenly surface old
 *     messages in every entrant's Communications archive, which is a
 *     UX surprise nobody signed up for. Instead we save the audience
 *     rule (type = match_entrants, value = {match_id, status_scope})
 *     so a Retract or Re-freeze workflow can rebuild the recipient
 *     list from current registrations if it's ever needed.
 *
 *   - The legacy `match_announcements` table is left untouched. If
 *     this migration ever needs to be re-run (e.g. a partial fail
 *     mid-way), the guard on `already_backfilled` below makes it
 *     idempotent.
 *
 *   - `sent_at` is preserved exactly so the Archive sort order reflects
 *     when the message actually went out, not when this migration ran.
 *
 *   - Status is set to `sent` (not `sending`) so nothing tries to
 *     re-fan-out an old message. Retention is `match_scoped` so any
 *     backfilled item where the match has already been marked
 *     completed/cancelled auto-vanishes from every member view.
 *
 * Down migration is intentionally a no-op: rolling this data migration
 * back after users have started reading/acknowledging the backfilled
 * rows would break foreign key relationships (recipients, deliveries)
 * that no longer point anywhere sensible. If a hard rollback is really
 * needed, operators should identify offending rows manually and
 * soft-delete them via the admin UI.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('match_announcements')) {
            return;
        }

        DB::table('match_announcements')
            ->orderBy('id')
            ->each(function ($row) {
                // Idempotency guard: skip rows we've already backfilled.
                // Match on match_id + sender + title + exact sent_at so
                // a re-run of this migration on a partially-applied DB
                // doesn't double-insert.
                $exists = DB::table('announcements')
                    ->where('match_id', $row->match_id)
                    ->where('created_by', $row->sender_user_id)
                    ->where('title', $row->subject)
                    ->where('sent_at', $row->sent_at)
                    ->exists();

                if ($exists) {
                    return;
                }

                $now = now();

                $announcementId = DB::table('announcements')->insertGetId([
                    'title' => $row->subject,
                    'body' => $row->body,
                    'category' => 'match_bulletin',
                    'retention' => 'match_scoped',
                    'match_id' => $row->match_id,
                    'priority' => 'normal',
                    'requires_acknowledgement' => false,
                    // Legacy channel was email-only. Leave deliver_via
                    // null so re-freezing (if ever done) fans out via
                    // the current default channels.
                    'deliver_via' => null,
                    'status' => 'sent',
                    'created_by' => $row->sender_user_id,
                    'approved_by' => null,
                    'approved_at' => null,
                    'send_at' => null,
                    'published_at' => $row->sent_at,
                    'expires_at' => null,
                    'sent_at' => $row->sent_at,
                    'recipient_count' => $row->recipient_count,
                    'retracted_at' => null,
                    'retracted_by' => null,
                    'retraction_reason' => null,
                    'created_at' => $row->created_at ?? $now,
                    'updated_at' => $row->updated_at ?? $now,
                    'deleted_at' => null,
                ]);

                // Persist the audience rule so a future Retract or
                // Recompute (freezeRecipients) can rebuild the recipient
                // list from current entrants without us guessing at
                // historical registrations.
                $statusScope = is_string($row->status_scope)
                    ? json_decode($row->status_scope, true)
                    : (is_array($row->status_scope) ? $row->status_scope : ['confirmed', 'waitlisted']);

                DB::table('announcement_audiences')->insert([
                    'announcement_id' => $announcementId,
                    'type' => 'match_entrants',
                    'mode' => 'include',
                    'value' => json_encode([
                        'match_id' => (int) $row->match_id,
                        'status_scope' => $statusScope ?: ['confirmed', 'waitlisted'],
                    ]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
    }

    public function down(): void
    {
        // Intentionally no-op. See docblock above.
    }
};
