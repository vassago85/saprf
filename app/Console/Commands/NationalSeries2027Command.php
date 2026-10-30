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
use App\Models\QualificationRule;
use App\Models\User;
use App\Services\Announcements\AnnouncementPublisher;
use App\Services\SettingsService;
use App\Services\StandingsCalculationService;
use Illuminate\Console\Command;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Storage;

/**
 * Switches the parked 2027 national series rules on (or back off).
 *
 *   status     what is currently live
 *   publish    show the rules document at /rules/national-series-2027, on
 *              /documents and in document search
 *   unpublish  park the document again (owner/admin preview only)
 *   activate   point the PRS + PR22 2027 qualification rules at the
 *              national_series_2027 scoring mode and rebuild 2027 standings
 *   notify     publish the rules page, then send active members a policy
 *              notice with both signed PDFs. Safe to run twice.
 */
class NationalSeries2027Command extends Command
{
    private const SEASON = '2027';

    private const SERIES = ['PRS', 'PR22'];

    /**
     * Stable title so a second `notify` does not email members again.
     */
    public const NOTICE_TITLE = '2027 national series rules and 2028 team selection';

    protected $signature = 'saprf:national-series-2027
        {action : status | publish | unpublish | activate | notify}
        {--force : Skip the confirmation prompt on activate and notify}';

    protected $description = 'Publish and activate the 2027 SAPRF national series rules';

    public function handle(SettingsService $settings, StandingsCalculationService $standings, AnnouncementPublisher $publisher): int
    {
        return match ($this->argument('action')) {
            'status' => $this->status($settings),
            'publish' => $this->setPublished($settings, true),
            'unpublish' => $this->setPublished($settings, false),
            'activate' => $this->activate($standings),
            'notify' => $this->notify($settings, $publisher),
            default => $this->invalidAction(),
        };
    }

    private function status(SettingsService $settings): int
    {
        $this->line('Rules document: '.($settings->nationalSeries2027Published() ? 'published' : 'parked (owner/admin preview only)'));

        foreach (self::SERIES as $series) {
            $rule = QualificationRule::where('series', $series)->where('season', self::SEASON)->first();
            $this->line(sprintf(
                '%s %s scoring: %s',
                $series,
                self::SEASON,
                $rule ? ($rule->isNationalSeries2027() ? 'active (national_series_2027)' : "not active ({$rule->scoring_mode})") : 'not active (no rule)',
            ));
        }

        return self::SUCCESS;
    }

    private function setPublished(SettingsService $settings, bool $published): int
    {
        $settings->set(
            SettingsService::NATIONAL_SERIES_2027_PUBLISHED,
            $published ? '1' : '0',
            'Show the 2027 national series rules document publicly',
        );

        $this->info($published
            ? 'Published: '.route('rules.national-series-2027')
            : 'Parked: the 2027 rules document is now owner/admin preview only.');

        return self::SUCCESS;
    }

    private function activate(StandingsCalculationService $standings): int
    {
        $existing = QualificationRule::whereIn('series', self::SERIES)->where('season', self::SEASON)->get()->keyBy('series');

        foreach (self::SERIES as $series) {
            $rule = $existing->get($series);
            $this->line(sprintf(
                '%s %s: %s -> national_series_2027 (best 3 nationals or international, max %d home province, championships as one match)',
                $series,
                self::SEASON,
                $rule ? $rule->scoring_mode : 'no rule',
                QualificationRule::NATIONAL_SERIES_2027_HOME_PROVINCE_CAP,
            ));
        }

        if (! $this->option('force') && ! $this->confirm('Switch these rules and rebuild the 2027 standings?')) {
            $this->warn('Nothing changed.');

            return self::FAILURE;
        }

        $createdBy = User::whereHas('roles', fn ($q) => $q->where('name', 'owner'))->orderBy('id')->value('id')
            ?? User::query()->min('id');

        foreach (self::SERIES as $series) {
            $rule = QualificationRule::firstOrNew(['series' => $series, 'season' => self::SEASON]);
            $rule->fill([
                'scoring_mode' => QualificationRule::MODE_NATIONAL_SERIES_2027,
                'best_of_count' => 3,
                'min_out_of_province_matches' => 1,
                'total_qualifying_matches' => 4,
                'weighted_final_enabled' => false,
            ]);
            $rule->created_by ??= $createdBy;
            $rule->save();

            $standings->recalculateSeasonStandings($series, self::SEASON);
            $standings->recalculateProvincialStandings($series, self::SEASON);
            $this->info("{$series} ".self::SEASON.' activated and standings rebuilt.');
        }

        return self::SUCCESS;
    }

    /**
     * One policy notice to active members, with both signed PDFs attached.
     * The Chair sends it, so the second-approver rule does not apply.
     * Running it again after a send is a no-op.
     */
    private function notify(SettingsService $settings, AnnouncementPublisher $publisher): int
    {
        $existing = Announcement::query()
            ->where('title', self::NOTICE_TITLE)
            ->where('status', '!=', AnnouncementStatus::Cancelled)
            ->first();

        if ($existing) {
            $this->info("Already {$existing->status->value}: \"{$existing->title}\" (#{$existing->id}). Nothing sent.");

            return self::SUCCESS;
        }

        $pdfs = [
            'saprf-national-series-2027.pdf' => public_path('publications/saprf-national-series-2027.pdf'),
            'saprf-iprf-team-selection-2028.pdf' => public_path('publications/saprf-iprf-team-selection-2028.pdf'),
        ];

        foreach ($pdfs as $name => $path) {
            if (! is_file($path)) {
                $this->error("Missing signed PDF: {$name}");

                return self::FAILURE;
            }
        }

        $chair = User::role('chair')->orderBy('id')->first();
        if (! $chair) {
            $this->error('No Chair account found. A policy notice has to be sent by the Chair.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm('Publish the 2027 rules and email them to active members?')) {
            $this->warn('Nothing sent.');

            return self::FAILURE;
        }

        $this->setPublished($settings, true);

        $seriesUrl = route('rules.national-series-2027');
        $selectionUrl = route('selection.policy.public', ['series' => 'prs', 'season' => '2028']);

        $announcement = Announcement::create([
            'title' => self::NOTICE_TITLE,
            'body' => <<<MD
The signed rules for next year's series and for the 2028 IPRF team are now published. Please read both. The original PDFs are attached.

**SAPRF 2027 national series, championships and qualifying for selection** (29 October 2026)

Centrefire and PR22. This covers divisions, one-day nationals, the SA Championships weighted as one match, the 500-point championship ranking, and the 400-point national-team selection score.

[Read the 2027 series rules]({$seriesUrl})

**IPRF World Championships selection process** (19 October 2026)

Centrefire team selection for the 2028 IPRF Precision Rifle World Championships. Federation and Protea colours apply to Centrefire and PR22. The national team eligibility form is completed online while you are logged in.

[Read the selection process]({$selectionUrl})
MD,
            'category' => AnnouncementCategory::PolicyChange,
            'retention' => AnnouncementRetention::Permanent,
            'priority' => AnnouncementPriority::High,
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

        foreach ($pdfs as $name => $path) {
            $stored = Storage::disk('announcements')->putFileAs((string) $announcement->id, new File($path), $name);

            AnnouncementAttachment::create([
                'announcement_id' => $announcement->id,
                'path' => $stored,
                'filename' => $name,
                'mime' => 'application/pdf',
                'size' => filesize($path) ?: 0,
            ]);
        }

        $publisher->sendNow($announcement);

        $this->info("Sent \"{$announcement->title}\" to active members.");

        return self::SUCCESS;
    }

    private function invalidAction(): int
    {
        $this->error('Action must be one of: status, publish, unpublish, activate, notify.');

        return self::FAILURE;
    }
}
