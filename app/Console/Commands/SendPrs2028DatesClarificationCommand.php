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
 * Publishes a one-off Policy-change notice telling active members that
 * the PRS 2028 selection policy now spells out the calendar dates for
 * the Qualifying Period and the team announcement.
 *
 * This command is idempotent: it looks up any existing announcement
 * with the same stable title (not cancelled) and bails out if one
 * exists. That makes it safe to call from the Docker entrypoint, which
 * runs on every `app` container restart, not just on a redeploy.
 *
 * It sends as the Chair so the Policy-change second-approver rule in
 * AnnouncementPublisher::assertReadyToSend() is satisfied. If no Chair
 * account exists yet, the command fails loudly with a message pointing
 * at the user who needs the Chair role — exactly the same discipline
 * as `saprf:national-series-2027 notify`.
 *
 * Deliberately no file attachment: the body links to the live
 * saprf.co.za/selection/prs-policy page which already carries the
 * updated timeline table and (if present) the signed PDF of the
 * original policy.
 */
class SendPrs2028DatesClarificationCommand extends Command
{
    /**
     * Stable title. The idempotency guard greps for this exact string,
     * so changing it would silently re-fire the broadcast on the next
     * container boot. Don't.
     */
    public const NOTICE_TITLE = 'Annexure A to the PRS 2028 selection policy — Qualifying Period dates';

    protected $signature = 'saprf:send-prs-2028-dates-clarification
        {--force : Skip the confirmation prompt}';

    protected $description = 'Email active members the PRS 2028 selection-dates clarification (idempotent).';

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

        $chair = User::role('chair')->orderBy('id')->first();
        if (! $chair) {
            // Policy-change approval checks the Chair role, not the
            // free-text exco_position. Someone can be Chair by title
            // and still fail the role check.
            $byPosition = User::query()
                ->whereRaw('LOWER(TRIM(exco_position)) IN (?, ?, ?)', ['chair', 'chairman', 'chairperson'])
                ->first();

            $this->error($byPosition
                ? "{$byPosition->name} ({$byPosition->email}) is ExCo Chair by position but does not have the Chair role. Give them the Chair role under Users, then run this again."
                : 'No Chair account found. A policy notice has to be sent by the Chair.');

            return self::FAILURE;
        }

        $this->line("Sending as {$chair->name} (Chair).");

        if (! $this->option('force') && ! $this->confirm('Email the PRS 2028 selection-dates clarification to active members now?')) {
            $this->warn('Nothing sent.');

            return self::FAILURE;
        }

        $policyUrl = route('selection.policy.public', ['series' => 'prs', 'season' => '2028']);

        $announcement = Announcement::create([
            'title' => self::NOTICE_TITLE,
            'body' => <<<MD
SAPRF has published **Annexure A** to the IPRF Precision Rifle World Championships 2028 selection policy (Centrefire / PRS). The annexure sets the calendar dates for the Qualifying Period and the team announcement for the 2028 cycle, under the policy's *Power to amend* clause.

| | Date |
|---|---|
| **Qualifying Period starts** | **1 January 2027** |
| **Qualifying Period ends** | **31 December 2027** |
| **National Team(s) announced** | **by 31 January 2028** |

Only matches shot inside the Qualifying Period count toward 2028 team selection. The signed body of the policy is otherwise unchanged.

Annexure A is published at the end of the policy document on the SAPRF website:

{$policyUrl}

Please tap **I acknowledge** so we have your receipt on file.

— SAPRF ExCo
MD,
            'category' => AnnouncementCategory::PolicyChange,
            'retention' => AnnouncementRetention::Permanent,
            'priority' => AnnouncementPriority::Normal,
            'requires_acknowledgement' => true,
            'deliver_via' => [
                DeliveryChannel::Database->value,
                DeliveryChannel::Mail->value,
                DeliveryChannel::WebPush->value,
            ],
            'status' => AnnouncementStatus::Draft,
            'created_by' => $chair->id,
        ]);

        $announcement->audiences()->create([
            'type' => AudienceType::ActiveMembers,
            'mode' => AudienceMode::Include,
            'value' => [],
        ]);

        $publisher->sendNow($announcement);

        $this->info("Sent \"{$announcement->title}\" to active members.");

        return self::SUCCESS;
    }
}
