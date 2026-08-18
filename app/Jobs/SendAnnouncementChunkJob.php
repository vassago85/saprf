<?php

namespace App\Jobs;

use App\Enums\AnnouncementCategory;
use App\Enums\DeliveryChannel;
use App\Enums\DeliveryStatus;
use App\Models\Announcement;
use App\Models\AnnouncementDelivery;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\Channels\WebPushChannel;
use App\Notifications\FederationAnnouncementNotification;
use App\Services\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Deliver a single (announcement, channel, [recipient user ids]) chunk.
 *
 * This is where the per-recipient mute preferences and the global
 * `notifications_enabled` kill switch are enforced for the outbound
 * side of the pipeline. In-app rows always exist regardless (see
 * AnnouncementPublisher::freezeRecipients) — that's what makes the
 * /communications archive complete even when Mail is off.
 *
 * The chunk itself is rate-limited when sending mail so N of these
 * chunk jobs can be dispatched fast without breaching Mailgun ceilings.
 */
class SendAnnouncementChunkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    /**
     * @param  array<int, int>  $userIds
     */
    public function __construct(
        public readonly int $announcementId,
        public readonly string $channel,
        public readonly array $userIds,
    ) {}

    public function middleware(): array
    {
        if ($this->channel === DeliveryChannel::Mail->value) {
            return [new RateLimited('mail')];
        }

        return [];
    }

    public function handle(SettingsService $settings, WebPushChannel $webPush): void
    {
        $announcement = Announcement::query()->find($this->announcementId);

        if (! $announcement) {
            Log::warning('SendAnnouncementChunkJob: announcement gone', ['id' => $this->announcementId]);
            return;
        }

        $channel = DeliveryChannel::from($this->channel);

        $users = User::query()
            ->whereIn('id', $this->userIds)
            ->with('notificationPreference')
            ->get()
            ->keyBy('id');

        $mailEnabled = $this->mailChannelIsEnabled($settings, $announcement->category);

        foreach ($this->userIds as $userId) {
            $user = $users->get($userId);

            $delivery = $this->deliveryRow($announcement, $userId, $channel);
            if (! $delivery) {
                continue;
            }

            if (! $user) {
                $delivery->markFailed('User not found at send time.');
                continue;
            }

            try {
                match ($channel) {
                    DeliveryChannel::Mail => $this->sendMail($user, $announcement, $delivery, $mailEnabled),
                    DeliveryChannel::WebPush => $this->sendPush($user, $announcement, $delivery, $webPush),
                    DeliveryChannel::Database => $delivery->markSent(), // Should not happen — DB rows are pre-marked sent.
                };
            } catch (Throwable $e) {
                Log::warning('SendAnnouncementChunkJob: delivery failure', [
                    'announcement_id' => $announcement->id,
                    'user_id' => $userId,
                    'channel' => $channel->value,
                    'error' => $e->getMessage(),
                ]);
                $delivery->markFailed($e->getMessage());
            }
        }
    }

    private function deliveryRow(Announcement $announcement, int $userId, DeliveryChannel $channel): ?AnnouncementDelivery
    {
        return AnnouncementDelivery::query()
            ->join('announcement_recipients', 'announcement_recipients.id', '=', 'announcement_deliveries.announcement_recipient_id')
            ->where('announcement_recipients.announcement_id', $announcement->id)
            ->where('announcement_recipients.user_id', $userId)
            ->where('announcement_deliveries.channel', $channel->value)
            ->select('announcement_deliveries.*')
            ->first();
    }

    private function sendMail(User $user, Announcement $announcement, AnnouncementDelivery $delivery, bool $mailEnabled): void
    {
        if (! $mailEnabled) {
            // Explicit failure captures "why" for the audit; category is
            // captured on the announcement so we know whether this bit
            // matters (Policy change / Urgent bypass the kill switch).
            $delivery->markFailed('Mail suppressed by notifications_enabled=false');
            return;
        }

        if (! $this->userWantsMailFor($user, $announcement->category)) {
            $delivery->markFailed('Recipient muted this category');
            return;
        }

        if (empty($user->email)) {
            $delivery->markFailed('Recipient has no deliverable email address');
            return;
        }

        // Prior hard-bounce / spam-complaint suppression. Mandatory
        // categories (Policy change / Urgent) bypass this — Exco still
        // needs the delivery record on the audit trail even if Mailgun
        // will refuse to deliver — but non-mandatory sends skip so we
        // don't grind sender reputation into the ground.
        if (! $announcement->category->isMandatory()) {
            if ($user->email_bounced_at !== null) {
                $delivery->markFailed('Email address hard-bounced previously; skipped', DeliveryStatus::Bounced);
                return;
            }
            if ($user->email_complained_at !== null) {
                $delivery->markFailed('Recipient marked previous SAPRF mail as spam; skipped', DeliveryStatus::Bounced);
                return;
            }
        }

        // Pass the delivery row through so the notification can inject
        // its id as a Mailgun user-variable — the webhook consumer uses
        // that to move the row through queued → sent → delivered / failed.
        $user->notify(new FederationAnnouncementNotification($announcement, $delivery));

        $delivery->markSent();
    }

    private function sendPush(User $user, Announcement $announcement, AnnouncementDelivery $delivery, WebPushChannel $webPush): void
    {
        if (! $this->userWantsPushFor($user, $announcement->category)) {
            $delivery->markFailed('Recipient muted push for this category');
            return;
        }

        $result = $webPush->sendAnnouncement($user, $announcement);

        if ($result['sent'] > 0) {
            $delivery->markSent();
            return;
        }

        if ($result['pruned'] > 0 && $result['sent'] === 0) {
            $delivery->markFailed('All push subscriptions pruned by push service (404/410)', DeliveryStatus::Bounced);
            return;
        }

        $delivery->markFailed('No active push subscriptions for user');
    }

    private function mailChannelIsEnabled(SettingsService $settings, AnnouncementCategory $category): bool
    {
        if ($category->isMandatory()) {
            return true;
        }

        try {
            return (bool) $settings->get('notifications_enabled', true);
        } catch (Throwable) {
            return true;
        }
    }

    private function userWantsMailFor(User $user, AnnouncementCategory $category): bool
    {
        $pref = $user->notificationPreference;
        if (! $pref) {
            return true;
        }

        return $pref->allowsEmailFor($category);
    }

    private function userWantsPushFor(User $user, AnnouncementCategory $category): bool
    {
        $pref = $user->notificationPreference;
        if (! $pref) {
            return true;
        }

        return $pref->allowsPushFor($category);
    }
}
