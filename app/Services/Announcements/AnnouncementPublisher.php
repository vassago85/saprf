<?php

namespace App\Services\Announcements;

use App\Enums\AnnouncementCategory;
use App\Enums\AnnouncementStatus;
use App\Enums\DeliveryChannel;
use App\Enums\DeliveryStatus;
use App\Jobs\ResolveAudienceJob;
use App\Models\Announcement;
use App\Models\AnnouncementDelivery;
use App\Models\AnnouncementRecipient;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Coordinates the lifecycle of an announcement from Send button to
 * delivery rows.
 *
 *   sendNow($announcement)      → enforces approval, marks status
 *                                  `sending`, and hands off to the
 *                                  fan-out (currently inline, will
 *                                  be a queued ResolveAudienceJob in
 *                                  Task 4).
 *   schedule($announcement, $t) → keeps status `scheduled` with
 *                                  `send_at`; the console scheduler
 *                                  picks it up when $t passes.
 *   approve($announcement, $u)  → runtime enforcement of the
 *                                  "different Exco/Chair must approve
 *                                  Policy change" rule that Gate::before
 *                                  can not enforce on its own.
 *   cancel($announcement)       → hard-stop for drafts/scheduled.
 *
 * Recipient snapshotting happens inside `freezeRecipients()`, which is
 * the *only* code path that writes announcement_recipients / delivery
 * rows. Anything else that thinks it needs those rows is a bug.
 */
class AnnouncementPublisher
{
    public function __construct(
        private readonly AudienceResolver $resolver,
    ) {}

    /**
     * Kick off a send. NEVER runs the actual freeze/fan-out inline —
     * we mark the row `sending` and dispatch ResolveAudienceJob so a
     * big broadcast doesn't hold the HTTP request. The pipeline is:
     *
     *   sendNow → ResolveAudienceJob → freezeRecipients (in-app rows
     *   + queued mail/push rows) → DispatchAnnouncementJob →
     *   SendAnnouncementChunkJob per channel
     */
    public function sendNow(Announcement $announcement): Announcement
    {
        $this->assertReadyToSend($announcement);

        DB::transaction(function () use ($announcement) {
            $announcement->forceFill([
                'status' => AnnouncementStatus::Sending,
                'published_at' => $announcement->published_at ?? now(),
            ])->save();
        });

        ResolveAudienceJob::dispatch($announcement->id);

        return $announcement->fresh();
    }

    public function schedule(Announcement $announcement, Carbon $sendAt): Announcement
    {
        $this->assertReadyToSend($announcement);

        if ($sendAt->isPast()) {
            throw new RuntimeException('Scheduled send time must be in the future.');
        }

        $announcement->forceFill([
            'status' => AnnouncementStatus::Scheduled,
            'send_at' => $sendAt,
        ])->save();

        return $announcement->fresh();
    }

    public function cancel(Announcement $announcement): Announcement
    {
        if (! in_array($announcement->status, [
            AnnouncementStatus::Draft,
            AnnouncementStatus::Scheduled,
        ], true)) {
            throw new RuntimeException('Only draft or scheduled announcements can be cancelled.');
        }

        $announcement->forceFill([
            'status' => AnnouncementStatus::Cancelled,
        ])->save();

        return $announcement->fresh();
    }

    /**
     * Soft-delete a draft or cancelled announcement and remove its
     * uploaded attachments from disk. Sent/sending/scheduled rows must
     * go through cancel/retract instead — hard-deleting them would
     * silently discard delivery evidence and audit chain.
     *
     * Cascading behaviour:
     *   - Attachment files: unlinked from the `announcements` disk.
     *   - Attachment DB rows: hard-deleted (foreign key to announcements).
     *   - Audience rules: hard-deleted (foreign key to announcements).
     *   - Announcement itself: soft-deleted (SoftDeletes trait) so a
     *     mis-click can still be recovered from `deleted_at` in the DB.
     *
     * Recipients / deliveries are not touched because no code path
     * creates them before `Sending → Sent` — a draft/cancelled row
     * has never been fanned out.
     */
    public function delete(Announcement $announcement): void
    {
        if (! in_array($announcement->status, [
            AnnouncementStatus::Draft,
            AnnouncementStatus::Cancelled,
        ], true)) {
            throw new RuntimeException(
                'Only draft or cancelled announcements can be deleted. '
                . 'Use cancel first, or retract if it has already been sent.'
            );
        }

        DB::transaction(function () use ($announcement) {
            foreach ($announcement->attachments as $attachment) {
                Storage::disk('announcements')->delete($attachment->path);
                $attachment->delete();
            }

            $announcement->audiences()->delete();

            $announcement->delete();
        });
    }

    /**
     * Retract a sent announcement — hide the /communications archive
     * copy from every member without pretending the email never went
     * out. The row is preserved (no delete of any kind) so the audit
     * trail, delivery stats, and Mailgun webhook correlation all keep
     * working. Only the member-facing UI changes: retracted rows
     * disappear from the archive index and 404 on direct-link.
     *
     * The reason is captured so an admin looking at the audit log a
     * year later knows *why* it went away — same discipline as the
     * `deletion_reason` on user account deletions.
     */
    public function retract(Announcement $announcement, User $actor, string $reason): Announcement
    {
        if ($announcement->status !== AnnouncementStatus::Sent) {
            throw new RuntimeException(
                'Only sent announcements can be retracted. '
                . 'Draft or scheduled announcements should be cancelled or deleted instead.'
            );
        }

        if ($announcement->isRetracted()) {
            throw new RuntimeException('This announcement has already been retracted.');
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('A retraction reason is required so the audit log is meaningful.');
        }

        $announcement->forceFill([
            'retracted_at' => now(),
            'retracted_by' => $actor->id,
            'retraction_reason' => mb_substr($reason, 0, 500),
        ])->save();

        return $announcement->fresh();
    }

    /**
     * Approve a Policy change. Runtime rules (Gate::before waves through
     * the author too, so we cannot rely on the policy alone):
     *   - author cannot approve their own draft
     *   - approver must have `exco` or `chair`
     */
    public function approve(Announcement $announcement, User $approver): Announcement
    {
        if ($announcement->category !== AnnouncementCategory::PolicyChange) {
            throw new RuntimeException('Only Policy change announcements require a second approver.');
        }

        if (! $approver->isExco()) {
            throw new RuntimeException('Only Exco or Chair members can approve announcements.');
        }

        if ($approver->id === $announcement->created_by) {
            throw new RuntimeException('An author cannot approve their own Policy change announcement.');
        }

        $announcement->forceFill([
            'approved_by' => $approver->id,
            'approved_at' => now(),
        ])->save();

        return $announcement->fresh();
    }

    /**
     * Snapshot the resolved audience into announcement_recipients and
     * create per-channel delivery rows. Idempotent: re-running after a
     * partial failure will skip recipients that already exist thanks to
     * the unique(announcement_id, user_id) constraint plus `firstOrCreate`.
     *
     * In-app (`database`) delivery rows are marked `sent` immediately
     * because the "delivery" for in-app is just the recipient row existing
     * — the /communications archive reads directly off it. Email + push
     * rows are created as `queued`; Task 4 wires the async jobs that flip
     * them to `sent` / `failed`.
     *
     * Returns the number of unique recipients frozen.
     */
    public function freezeRecipients(Announcement $announcement): int
    {
        $userIds = $this->resolver->resolve($announcement->audiences()->get());

        DB::transaction(function () use ($announcement, $userIds) {
            foreach ($userIds as $userId) {
                $recipient = AnnouncementRecipient::firstOrCreate([
                    'announcement_id' => $announcement->id,
                    'user_id' => $userId,
                ]);

                $this->seedDeliveryRow($recipient, DeliveryChannel::Database, markSent: true);

                if ($announcement->deliversVia(DeliveryChannel::Mail)) {
                    $this->seedDeliveryRow($recipient, DeliveryChannel::Mail);
                }

                if ($announcement->deliversVia(DeliveryChannel::WebPush)) {
                    $this->seedDeliveryRow($recipient, DeliveryChannel::WebPush);
                }
            }

            $announcement->forceFill([
                'status' => AnnouncementStatus::Sent,
                'sent_at' => $announcement->sent_at ?? now(),
                'recipient_count' => $userIds->count(),
            ])->save();
        });

        return $userIds->count();
    }

    private function seedDeliveryRow(
        AnnouncementRecipient $recipient,
        DeliveryChannel $channel,
        bool $markSent = false,
    ): AnnouncementDelivery {
        $delivery = AnnouncementDelivery::firstOrCreate(
            [
                'announcement_recipient_id' => $recipient->id,
                'channel' => $channel,
            ],
            [
                'status' => $markSent ? DeliveryStatus::Sent : DeliveryStatus::Queued,
                'sent_at' => $markSent ? now() : null,
            ],
        );

        return $delivery;
    }

    private function assertReadyToSend(Announcement $announcement): void
    {
        if (! $announcement->status->isEditable()) {
            throw new RuntimeException("Announcement is {$announcement->status->value}; only draft or scheduled announcements can be sent.");
        }

        if ($announcement->needsApproval()) {
            throw new RuntimeException('This Policy change announcement needs a second Exco or Chair approver before it can be sent.');
        }

        if ($announcement->audiences()->count() === 0) {
            throw new RuntimeException('Announcement has no audience rules — nothing to send.');
        }
    }
}
