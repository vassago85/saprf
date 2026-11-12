<?php

namespace App\Console\Commands;

use App\Enums\AnnouncementCategory;
use App\Enums\AnnouncementPriority;
use App\Enums\AnnouncementRetention;
use App\Enums\AnnouncementStatus;
use App\Enums\AudienceMode;
use App\Enums\AudienceType;
use App\Enums\DeliveryChannel;
use App\Models\Announcement;
use App\Models\User;
use App\Services\Announcements\AnnouncementPublisher;
use Illuminate\Console\Command;

/**
 * One-off notice introducing the Decision Register to active (paid-up)
 * members. Idempotent by stable title — safe to re-run; will not double-send.
 *
 * Do not wire this into the Docker entrypoint. Run it once manually after
 * deploy:
 *
 *   docker exec saprf-app php artisan saprf:send-decision-register-intro --force
 */
class SendDecisionRegisterIntroCommand extends Command
{
    /**
     * Stable title used for the idempotency guard. Do not change after
     * the notice has been sent in production.
     */
    public const NOTICE_TITLE = 'New: Decision Register';

    protected $signature = 'saprf:send-decision-register-intro
        {--force : Skip the confirmation prompt}';

    protected $description = 'Email active paid-up members the Decision Register introduction (idempotent).';

    public function handle(AnnouncementPublisher $publisher): int
    {
        $existing = Announcement::query()
            ->where('title', self::NOTICE_TITLE)
            ->where('status', '!=', AnnouncementStatus::Cancelled)
            ->first();

        if ($existing) {
            $this->info("Already {$existing->status->value}: \"{$existing->title}\" (#{$existing->id}). Nothing sent.");

            return self::SUCCESS;
        }

        $sender = User::role('chair')->orderBy('id')->first()
            ?? User::role('exco')->orderBy('id')->first();

        if ($sender === null) {
            $this->error('No ExCo or Chair account found. Create one, then run this again.');

            return self::FAILURE;
        }

        $this->line("Sending as {$sender->name}.");

        if (! $this->option('force') && ! $this->confirm('Email the Decision Register introduction to active paid-up members now?')) {
            $this->warn('Nothing sent.');

            return self::FAILURE;
        }

        $registerUrl = route('decisions.index');

        $announcement = Announcement::create([
            'title' => self::NOTICE_TITLE,
            'body' => <<<MD
Members,

We’ve added a **Decision Register** on the SAPRF website. Items decided at ExCo will be published there shortly, so you can see what was decided and give anonymous feedback.

**How it works**

- Find it under **Decisions** once you are logged in, or open the link from the notice when a decision goes live.
- Active paid-up members can react with 👍 For, 👎 Against, or 🤷 Neutral.
- Voting does not immediately change the decision — the decision stands as made. It gives ExCo an idea of whether a decision needs to be relooked at.

**Anonymity**

To protect anonymity, you may vote only once. The system does not record which option you chose against your name — only that you voted, so you cannot vote twice. **Be careful how you use your vote.**

Feedback is open for a limited window after each decision is published (normally seven days). After that, the final tally stays on the register.

{$registerUrl}

Thank you for taking part thoughtfully.

SAPRF ExCo
MD,
            'category' => AnnouncementCategory::PlatformUpdate,
            'retention' => AnnouncementRetention::Permanent,
            'priority' => AnnouncementPriority::Normal,
            'requires_acknowledgement' => false,
            'deliver_via' => [
                DeliveryChannel::Database->value,
                DeliveryChannel::Mail->value,
                DeliveryChannel::WebPush->value,
            ],
            'status' => AnnouncementStatus::Draft,
            'created_by' => $sender->id,
        ]);

        $announcement->audiences()->create([
            'type' => AudienceType::ActiveMembers,
            'mode' => AudienceMode::Include,
            'value' => [],
        ]);

        $publisher->sendNow($announcement);

        $this->info("Sent \"{$announcement->title}\" to active paid-up members.");

        return self::SUCCESS;
    }
}
