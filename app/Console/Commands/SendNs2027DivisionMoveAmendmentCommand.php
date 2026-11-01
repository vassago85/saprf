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
use App\Models\AnnouncementAttachment;
use App\Models\User;
use App\Services\Announcements\AnnouncementPublisher;
use Illuminate\Console\Command;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Storage;

/**
 * One-off Policy-change notice that tells active members the signed
 * 2027 national series rules have been amended: the division-move
 * paragraph in the "Series log and ranking" section used to carry an
 * exception for shooters moving Open → Factory with a factory-qualified
 * rifle. That exception has been removed so the rule is uniform
 * regardless of which rifle was used in Open.
 *
 * The command is idempotent — it looks up any existing announcement
 * with the stable title (not cancelled) and bails if one exists. Safe
 * to call from the Docker entrypoint, which runs on every `app`
 * container restart.
 *
 * The amended signed PDF ships as an attachment so members have the
 * authoritative document in their inbox even if they never open the
 * portal.
 */
class SendNs2027DivisionMoveAmendmentCommand extends Command
{
    /**
     * Stable title. The idempotency guard greps for this exact string,
     * so changing it would silently re-fire the broadcast on the next
     * container boot. Don't.
     */
    public const NOTICE_TITLE = '2027 national series rules — division-move clause amended';

    /**
     * Signed PDF filename as served from public/publications.
     */
    private const PDF_FILENAME = 'saprf-national-series-2027.pdf';

    protected $signature = 'saprf:send-ns-2027-division-move-amendment
        {--force : Skip the confirmation prompt}';

    protected $description = 'Email active members the 2027 national series division-move amendment (idempotent).';

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

        $pdfPath = public_path('publications/'.self::PDF_FILENAME);
        if (! is_file($pdfPath)) {
            $this->error("Missing signed PDF at {$pdfPath}. Deploy the amended PDF before firing this notice.");

            return self::FAILURE;
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

        if (! $this->option('force') && ! $this->confirm('Email the 2027 national series division-move amendment to active members now?')) {
            $this->warn('Nothing sent.');

            return self::FAILURE;
        }

        $rulesUrl = route('rules.national-series-2027');

        $announcement = Announcement::create([
            'title' => self::NOTICE_TITLE,
            'body' => <<<MD
The signed **SAPRF 2027 national series, championships and qualifying for selection** document has been amended. The division-move paragraph in the Series log and ranking section has been shortened.

**Before:**

> A shooter moving from Factory to Open keeps their scores, as those rifles meet the Open rules. A shooter moving from Open to Factory starts again, except if the rifle used to shoot in Open was a factory qualified rifle.

**Now:**

> A shooter moving from Factory to Open keeps their scores, as those rifles meet the Open rules. A shooter moving from Open to Factory starts again.

The exception for a factory-qualified rifle has been removed, so a shooter who moves from Open to Factory mid-season always starts the Factory ranking from zero. Every other sentence in the document is unchanged.

The amended signed PDF is attached to this notice and is also published on the SAPRF website:

{$rulesUrl}

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

        $stored = Storage::disk('announcements')->putFileAs(
            (string) $announcement->id,
            new File($pdfPath),
            self::PDF_FILENAME,
        );

        AnnouncementAttachment::create([
            'announcement_id' => $announcement->id,
            'path' => $stored,
            'filename' => self::PDF_FILENAME,
            'mime' => 'application/pdf',
            'size' => filesize($pdfPath) ?: 0,
        ]);

        $publisher->sendNow($announcement);

        $this->info("Sent \"{$announcement->title}\" to active members.");

        return self::SUCCESS;
    }
}
