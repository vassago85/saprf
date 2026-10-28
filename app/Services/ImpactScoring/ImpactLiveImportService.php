<?php

namespace App\Services\ImpactScoring;

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Score;
use App\Models\ScoreImport;
use App\Models\ShooterLog;
use App\Models\User;
use App\Notifications\ImpactImportCollectionReportNotification;
use App\Services\AuditLogService;
use App\Services\MembershipValidationService;
use App\Services\RegistrationPricingService;
use App\Services\ScoreValidationService;
use App\Services\StandingsCalculationService;
use App\Services\WalkInRegistrationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class ImpactLiveImportService
{
    /** Impact division name → SAPRF slug (registration still wins when present). */
    private const DIVISION_ALIASES = [
        'production' => 'factory',
        'factory' => 'factory',
        'open' => 'open',
        'limited' => 'limited',
        'semi-auto' => 'open',
        'semiauto' => 'open',
        'airgun' => 'open',
        'ladies' => 'ladies',
        'lady' => 'ladies',
        'junior' => 'junior',
        'juniors' => 'junior',
        'senior' => 'senior',
        'seniors' => 'senior',
    ];

    public function __construct(
        private readonly ImpactScoringClient $client,
        private readonly ImpactNameMatcher $names,
        private readonly WalkInRegistrationService $walkIns,
        private readonly RegistrationPricingService $pricing,
        private readonly MembershipValidationService $membership,
        private readonly ScoreValidationService $scoreValidation,
        private readonly StandingsCalculationService $standings,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * Build a preview payload for the Impact live-pull UI.
     *
     * @param  list<int>  $day1StageIds
     * @return array<string, mixed>
     */
    public function preview(
        MatchEvent $match,
        string $impactRef,
        ?string $scoreScope = null,
        array $day1StageIds = [],
        bool $includeStages = true,
    ): array {
        $impactId = $this->client->parseMatchId($impactRef);
        $live = $this->client->getLiveScores($impactId);
        $impactMatch = $live['match'];
        $stages = $live['stages'];
        $dayByStageId = ImpactStageDays::dayByStageId($stages);
        $idsByDay = ImpactStageDays::idsByDay($stages);
        if ($scoreScope === 'day1' && $day1StageIds === []) {
            $day1StageIds = $idsByDay[1] ?? [];
        }

        $registrations = $match->registrations()
            ->with(['user:id,name,email', 'division:id,name,slug'])
            ->where(function ($q) {
                $q->whereNull('registration_status')
                    ->orWhereNotIn('registration_status', ['cancelled']);
            })
            ->get();

        $candidates = $registrations->map(fn (MatchRegistration $r) => [
            'key' => 'reg:'.$r->id,
            'name' => $r->user?->name ?: (string) $r->shooter_name,
            'registration' => $r,
        ])->all();

        $candidateNameList = array_map(
            fn ($c) => ['key' => $c['key'], 'name' => $c['name']],
            $candidates
        );

        $anyZeroPoints = false;
        foreach ($live['shooters'] as $shooterRow) {
            if ((int) ($shooterRow['points'] ?? 0) === 0) {
                $anyZeroPoints = true;
                break;
            }
        }

        $needStages = $includeStages || $day1StageIds !== [] || $scoreScope === 'day1' || $anyZeroPoints;
        $stagesByShooter = [];
        if ($needStages) {
            $ids = array_map(fn ($s) => (int) ($s['id'] ?? 0), $live['shooters']);
            try {
                $stagesByShooter = $this->client->getShooterStagesMany($impactId, $ids);
            } catch (\Throwable) {
                $stagesByShooter = [];
            }
        }

        $rows = [];
        foreach ($live['shooters'] as $shooter) {
            $impactName = trim((string) ($shooter['name'] ?? ''));
            $impacts = ((int) ($shooter['points'] ?? 0)) / 100;
            $impactDivision = (string) ($shooter['divisionName'] ?? '');
            $matchResult = $this->names->match($impactName, $candidateNameList);

            $linkedReg = null;
            if ($matchResult['status'] === 'exact' && $matchResult['key']) {
                $linkedReg = $registrations->first(
                    fn (MatchRegistration $r) => 'reg:'.$r->id === $matchResult['key']
                );
            }

            $shooterId = (int) ($shooter['id'] ?? 0);
            $stageDetail = $stagesByShooter[$shooterId] ?? [];
            foreach ($stageDetail as &$stageRow) {
                $stageId = (int) ($stageRow['id'] ?? 0);
                $stageRow['day'] = $dayByStageId[$stageId]
                    ?? ImpactStageDays::dayNumber((string) ($stageRow['name'] ?? ''))
                    ?? 1;
            }
            unset($stageRow);

            // The match-level total covers every day. When that field is 0,
            // add each stage score instead of storing a zero.
            if ($impacts <= 0 && $stageDetail !== []) {
                $impacts = 0.0;
                foreach ($stageDetail as $stageRow) {
                    $impacts += (float) ($stageRow['hits'] ?? 0);
                }
            }

            $day1Total = $this->sumStages($stageDetail, $day1StageIds);
            $membershipKind = $this->classifyImpactShooter($impactName, $linkedReg);

            $rows[] = [
                'impact_shooter_id' => (int) ($shooter['id'] ?? 0),
                'impact_name' => $impactName,
                'impact_rank' => $shooter['rank'] ?? null,
                'impact_division' => $impactDivision,
                'impacts' => $impacts,
                'day1_impacts' => $day1Total,
                'stages' => $stageDetail,
                'match_status' => $matchResult['status'],
                'fuzzy_candidates' => $matchResult['candidates'],
                'registration_id' => $linkedReg?->id,
                'registration_name' => $linkedReg
                    ? ($linkedReg->user?->name ?: $linkedReg->shooter_name)
                    : null,
                'registration_status' => $linkedReg?->registration_status,
                'payment_status' => $linkedReg?->payment_status,
                'division_slug' => $this->resolveDivisionSlug($linkedReg, $impactDivision),
                'division_source' => $linkedReg?->division_id ? 'registration' : 'impact',
                'membership_kind' => $membershipKind,
                'action' => $this->previewAction($linkedReg, $membershipKind),
            ];
        }

        $isTwoDayNational = $match->isTwoDayNational();

        return [
            'impact_match_id' => $impactId,
            'impact_match_name' => $impactMatch['name'] ?? 'Impact match #'.$impactId,
            'impact_match_ended' => (bool) ($impactMatch['matchEnded'] ?? false),
            'impact_stages' => array_map(fn ($s) => [
                'id' => (int) ($s['id'] ?? 0),
                'name' => (string) ($s['name'] ?? 'Stage'),
                'day' => $dayByStageId[(int) ($s['id'] ?? 0)] ?? 1,
            ], $stages),
            'stage_days' => $this->stageDaySummary($idsByDay),
            'saprf_match_id' => $match->id,
            'saprf_match_name' => $match->name,
            'is_two_day_national' => $isTwoDayNational,
            'score_scope' => $scoreScope,
            'day1_stage_ids' => array_values(array_map('intval', $day1StageIds)),
            'shooters' => $rows,
            'summary' => [
                'total' => count($rows),
                'exact' => count(array_filter($rows, fn ($r) => $r['match_status'] === 'exact')),
                'fuzzy' => count(array_filter($rows, fn ($r) => $r['match_status'] === 'fuzzy')),
                'unmatched' => count(array_filter($rows, fn ($r) => $r['match_status'] === 'unmatched')),
                'pending_payment' => count(array_filter(
                    $rows,
                    fn ($r) => $r['registration_id']
                        && ! in_array($r['payment_status'], ['paid', 'waived'], true)
                )),
            ],
        ];
    }

    /**
     * Commit an Impact live pull into ScoreImport + Score rows.
     *
     * @param  array{
     *   impact_ref:string,
     *   score_scope?:?string,
     *   day1_stage_ids?:list<int>,
     *   replace_existing?:bool,
     *   links?:array<string,string>,
     *   score_overrides?:array<string,array{impacts?:float|int|string,day1_impacts?:float|int|string,note?:string}>,
     * }  $options
     *   links map impact_shooter_id => "reg:{id}" | "skip" | "assign_later" | "auto"
     *   score_overrides map impact_shooter_id => adjusted totals (MD override of Impact)
     */
    public function import(MatchEvent $selectedMatch, User $actor, array $options): ScoreImport
    {
        $impactRef = (string) ($options['impact_ref'] ?? '');
        $scoreScope = $options['score_scope'] ?? null;
        $day1StageIds = array_values(array_map('intval', $options['day1_stage_ids'] ?? []));
        $replaceExisting = (bool) ($options['replace_existing'] ?? false);
        /** @var array<string, string> $links */
        $links = $options['links'] ?? [];
        /** @var array<string, array{impacts?:mixed,day1_impacts?:mixed,note?:string}> $scoreOverrides */
        $scoreOverrides = $options['score_overrides'] ?? [];

        if ($selectedMatch->isTwoDayNational() && ! in_array($scoreScope, ['day1', 'overall'], true)) {
            throw new RuntimeException('Choose Day 1 or Overall for a 2-day national.');
        }

        $preview = $this->preview(
            $selectedMatch,
            $impactRef,
            $scoreScope,
            $day1StageIds,
            includeStages: true,
        );

        if ($scoreScope === 'day1') {
            $resolvedDay1 = array_values(array_filter(array_map(
                'intval',
                $preview['day1_stage_ids'] ?? []
            )));
            if ($resolvedDay1 !== []) {
                $day1StageIds = $resolvedDay1;
            }
        }

        if ($selectedMatch->isTwoDayNational() && $scoreScope === 'day1' && $day1StageIds === []) {
            throw new RuntimeException('Impact did not return any Day 1 stages to add up.');
        }

        $targetMatch = $selectedMatch;
        $notes = ['Impact live pull #'.$preview['impact_match_id'].' ('.$preview['impact_match_name'].').'];

        if ($selectedMatch->isTwoDayNational() && $scoreScope === 'day1') {
            $targetMatch = $selectedMatch->findOrCreateProvincialDay1Sibling();
            $notes[] = "Day 1 provincial sibling #{$targetMatch->id}; stages ".implode(',', $day1StageIds).'.';
        } elseif ($selectedMatch->isTwoDayNational() && $scoreScope === 'overall') {
            $notes[] = 'Overall scores on the national match.';
        }

        return DB::transaction(function () use (
            $selectedMatch, $targetMatch, $actor, $preview, $scoreScope, $day1StageIds,
            $replaceExisting, $links, $scoreOverrides, $notes
        ) {
            if ($replaceExisting) {
                $scoreIds = Score::where('match_id', $targetMatch->id)->pluck('id');
                ShooterLog::whereIn('score_id', $scoreIds)->delete();
                ShooterLog::where('match_id', $targetMatch->id)->delete();
                Score::where('match_id', $targetMatch->id)->delete();
                $notes[] = 'Existing scores cleared before import.';
            }

            $import = ScoreImport::query()->create([
                'match_id' => $targetMatch->id,
                'uploaded_by' => $actor->id,
                'source_type' => 'impact_live',
                'day' => null,
                'original_filename' => 'impact:'.$preview['impact_match_id'],
                'import_status' => 'processing',
                'notes' => implode(' ', $notes),
            ]);

            $auditRows = [];
            $count = 0;

            foreach ($preview['shooters'] as $row) {
                $impactShooterId = (string) $row['impact_shooter_id'];
                $link = $links[$impactShooterId] ?? ($links[(int) $impactShooterId] ?? null);

                // Fuzzy without confirmation → skip (audit)
                if ($row['match_status'] === 'fuzzy' && ($link === null || $link === '' || $link === 'auto')) {
                    $auditRows[] = $this->auditRow($row, 'skipped_fuzzy');

                    continue;
                }
                if ($link === 'skip') {
                    $auditRows[] = $this->auditRow($row, 'skipped');

                    continue;
                }

                $assignLater = $link === 'assign_later';

                $registration = null;
                if (! $assignLater && is_string($link) && str_starts_with($link, 'reg:')) {
                    $registration = MatchRegistration::query()
                        ->where('match_id', $selectedMatch->id)
                        ->where('id', (int) substr($link, 4))
                        ->first();
                } elseif (! $assignLater && $row['match_status'] === 'exact' && $row['registration_id']) {
                    $registration = MatchRegistration::query()->find($row['registration_id']);
                }

                $override = $scoreOverrides[$impactShooterId]
                    ?? $scoreOverrides[(string) (int) $impactShooterId]
                    ?? [];
                $impactTotal = (float) $row['impacts'];
                $impactDay1 = (float) $row['day1_impacts'];
                $overrideNote = trim((string) ($override['note'] ?? ''));
                $sentTotal = array_key_exists('impacts', $override) && is_numeric($override['impacts']);
                $sentDay1 = array_key_exists('day1_impacts', $override) && is_numeric($override['day1_impacts']);
                $adjustedTotal = $sentTotal ? (float) $override['impacts'] : $impactTotal;
                $adjustedDay1 = $sentDay1 ? (float) $override['day1_impacts'] : $impactDay1;
                // A posted 0 with no note is the old preview (day total left at
                // zero). The stage-by-stage total is the score that counts.
                if ($overrideNote === '') {
                    if ($sentTotal && $adjustedTotal == 0.0 && $impactTotal > 0) {
                        $adjustedTotal = $impactTotal;
                    }
                    if ($sentDay1 && $adjustedDay1 == 0.0 && $impactDay1 > 0) {
                        $adjustedDay1 = $impactDay1;
                    }
                }
                $wasAdjusted = abs($adjustedTotal - $impactTotal) > 0.0001
                    || abs($adjustedDay1 - $impactDay1) > 0.0001;
                $adjustNote = $overrideNote;
                if ($wasAdjusted && $adjustNote === '') {
                    $adjustNote = 'adjusted from Impact';
                }

                $rawScore = $scoreScope === 'day1' ? $adjustedDay1 : $adjustedTotal;
                $needsMemberLink = false;
                $auditReason = null;

                if ($assignLater) {
                    $stub = $this->createAssignLaterStub($row['impact_name']);
                    $userId = $stub->id;
                    $divisionId = $this->divisionIdFromSlug($row['division_slug'] ?: 'open');
                    $shooterName = $row['impact_name'];
                    $needsMemberLink = true;
                    $auditReason = 'assign_later';
                } elseif ($registration) {
                    $userId = $registration->user_id;
                    $divisionId = $registration->division_id
                        ?: $this->divisionIdFromSlug($row['division_slug']);
                    $shooterName = $registration->user?->name ?: $registration->shooter_name ?: $row['impact_name'];

                    if ($this->needsFeeCollection($registration)) {
                        // Unpaid/pending entry — track for the collection report but
                        // do NOT rewrite md_net to a negative deduction. The row
                        // keeps its normal priced breakdown (fee > 0, md_net > 0)
                        // and the portal entry gate stops the shooter from
                        // entering another match until they settle the fee.
                        $auditReason = 'pending_unpaid';
                        $auditRows[] = $this->auditRow($row, 'pending_unpaid', [
                            'registration_id' => $registration->id,
                            'registration_status' => $registration->registration_status,
                            'payment_status' => $registration->payment_status,
                            'fee_amount' => (float) $registration->fee_amount,
                            'saprf_fee' => (float) $registration->saprf_fee,
                            'platform_fee' => (float) $registration->platform_fee,
                            'linked_name' => $shooterName,
                        ]);
                    }
                } else {
                    // Unmatched: member walk-in vs non-member stub
                    $resolved = $this->resolveUnmatchedShooter(
                        $selectedMatch,
                        $row,
                        $actor,
                    );
                    $userId = $resolved['user_id'];
                    $divisionId = $resolved['division_id'];
                    $shooterName = $row['impact_name'];
                    if ($resolved['collection'] ?? null) {
                        $auditReason = 'walk_in_member';
                        $auditRows[] = array_merge(
                            $this->auditRow($row, 'walk_in_member'),
                            $resolved['collection'],
                        );
                    } else {
                        $auditReason = 'non_member_stub';
                        $auditRows[] = $this->auditRow($row, 'non_member_stub', [
                            'user_id' => $userId,
                        ]);
                    }
                }

                if ($assignLater) {
                    $auditRows[] = $this->auditRow($row, 'assign_later', [
                        'user_id' => $userId,
                    ]);
                }

                $score = new Score;
                $score->fill([
                    'match_id' => $targetMatch->id,
                    'score_import_id' => $import->id,
                    'shooter_name' => $shooterName,
                    'user_id' => $userId,
                    'placement' => is_numeric($row['impact_rank'] ?? null) ? (int) $row['impact_rank'] : null,
                    'division_id' => $divisionId,
                    'status' => 'pending',
                    'is_member' => false,
                    'match_date' => $targetMatch->match_date,
                    'day1_raw_score' => $rawScore,
                    'raw_meta' => [
                        'impact' => [
                            'match_id' => $preview['impact_match_id'],
                            'shooter_id' => $row['impact_shooter_id'],
                            'impact_name' => $row['impact_name'],
                            'impact_division' => $row['impact_division'],
                            'division_source' => $row['division_source'],
                            'score_scope' => $scoreScope,
                            'day1_stage_ids' => $day1StageIds,
                            'stages' => $row['stages'],
                            'total_impacts' => $impactTotal,
                            'day1_impacts' => $impactDay1,
                            'adjusted' => $wasAdjusted,
                            'adjusted_total_impacts' => $adjustedTotal,
                            'adjusted_day1_impacts' => $adjustedDay1,
                            'adjustment_note' => $wasAdjusted ? $adjustNote : null,
                            'needs_member_link' => $needsMemberLink,
                            'audit_reason' => $auditReason,
                        ],
                    ],
                ]);
                $score->save();
                $this->scoreValidation->evaluateScoreStatus($score);
                $count++;
            }

            $auditSummary = $this->summarizeAudit($auditRows);
            $summary = "Imported {$count} shooters from Impact.";
            if ($auditRows !== []) {
                $summary .= ' Audit: '.count($auditRows).' follow-up row(s)'
                    .' (unpaid '.$auditSummary['pending_unpaid']
                    .', walk-ins '.$auditSummary['walk_in_member']
                    .', assign-later '.$auditSummary['assign_later']
                    .', skipped '.($auditSummary['skipped_fuzzy'] + $auditSummary['skipped'])
                    .', non-members '.$auditSummary['non_member_stub'].').';
            }

            $import->update([
                'import_status' => 'completed',
                'notes' => trim(($import->notes ?? '').PHP_EOL.$summary),
                'raw_payload' => [
                    'impact_match_id' => $preview['impact_match_id'],
                    'impact_match_name' => $preview['impact_match_name'],
                    'score_scope' => $scoreScope,
                    'day1_stage_ids' => $day1StageIds,
                    'imported_count' => $count,
                    'audit' => $auditRows,
                    'audit_summary' => $auditSummary,
                ],
            ]);

            try {
                $this->standings->recalculateForMatch($targetMatch->fresh());
            } catch (\Throwable $e) {
                report($e);
            }

            $this->autoCompleteMatch($targetMatch->fresh());

            $this->audit->log(
                $actor,
                'score.import.impact_live',
                'ScoreImport',
                $import->id,
                null,
                [
                    'impact_match_id' => $preview['impact_match_id'],
                    'row_count' => $count,
                    'audit_count' => count($auditRows),
                    'score_scope' => $scoreScope,
                ]
            );

            if ($auditRows !== []) {
                $md = $selectedMatch->creator;
                if ($md) {
                    $md->notify(new ImpactImportCollectionReportNotification(
                        $selectedMatch,
                        $auditRows,
                        $actor,
                        $import,
                    ));
                }
            }

            return $import->fresh();
        });
    }

    /**
     * Link an assign-later Impact score to a real match entry (registration).
     * Works for members and non-members. Registration division always wins.
     * Name-only entries (no user_id) keep the stub account so the score stays
     * visible as non_member rather than becoming an orphan.
     */
    public function linkScoreToRegistration(Score $score, MatchRegistration $registration, User $actor): Score
    {
        $meta = is_array($score->raw_meta) ? $score->raw_meta : [];
        if (empty($meta['impact']['needs_member_link'])) {
            throw new RuntimeException('This score is not waiting to be linked to an entry.');
        }

        $score->loadMissing('match');
        $registration->loadMissing(['user', 'division', 'match']);

        if ((int) $registration->match_id !== (int) $score->match_id) {
            $regMatch = $registration->match;
            $day1Id = $regMatch?->provincialDay1Match?->id;
            if ((int) $day1Id !== (int) $score->match_id) {
                throw new RuntimeException('That entry belongs to a different match.');
            }
        }

        $stubUserId = $score->user_id;

        // Keep the Impact / scoreboard name as-is (e.g. Kaden), even when the
        // linked entry was booked under a different label (e.g. Karen).
        $impactName = trim((string) ($meta['impact']['impact_name'] ?? ''))
            ?: trim((string) $score->shooter_name)
            ?: ($registration->user?->name ?: $registration->shooter_name);
        $entryName = $registration->user?->name
            ?: $registration->shooter_name
            ?: $impactName;

        // Prefer the entry's user (member or non-member account). Name-only
        // walk-ins have no user_id — keep the assign-later stub so the score
        // remains visible on the match as a non-member result.
        $linkedUserId = $registration->user_id ?: $stubUserId;
        $keptStubForNameOnlyEntry = $registration->user_id === null && $stubUserId;

        if ($keptStubForNameOnlyEntry && $stubUserId) {
            User::query()->whereKey($stubUserId)->update(['name' => $impactName]);
        }

        $meta['impact']['needs_member_link'] = false;
        $meta['impact']['linked_at'] = now()->toIso8601String();
        $meta['impact']['linked_by'] = $actor->id;
        $meta['impact']['linked_registration_id'] = $registration->id;
        $meta['impact']['division_source'] = 'registration';
        $meta['impact']['previous_stub_user_id'] = $stubUserId;
        $meta['impact']['linked_name_only_entry'] = (bool) $keptStubForNameOnlyEntry;
        $meta['impact']['linked_entry_name'] = $entryName;
        $meta['impact']['kept_impact_name'] = $impactName !== $entryName;

        $score->fill([
            'user_id' => $linkedUserId,
            'division_id' => $registration->division_id ?: $score->division_id,
            'shooter_name' => $impactName,
            'raw_meta' => $meta,
        ]);
        $score->save();

        // Unpaid/pending link — do not mutate the entry's financial breakdown.
        // The shooter still owes the normal priced fee and the portal entry
        // gate blocks any further entries until they pay.

        $this->scoreValidation->evaluateScoreStatus($score->fresh());

        try {
            $this->standings->recalculateForMatch($score->match->fresh());
        } catch (\Throwable $e) {
            report($e);
        }

        $this->audit->log(
            $actor,
            'score.impact.link_entry',
            'Score',
            $score->id,
            ['stub_user_id' => $stubUserId],
            [
                'user_id' => $linkedUserId,
                'registration_id' => $registration->id,
                'division_id' => $score->division_id,
                'name_only_entry' => (bool) $keptStubForNameOnlyEntry,
            ]
        );

        if (! $keptStubForNameOnlyEntry) {
            $this->maybeDeleteAssignLaterStub($stubUserId);
        }

        return $score->fresh(['user', 'division', 'match']);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function auditRow(array $row, string $reason, array $extra = []): array
    {
        return array_merge([
            'name' => $row['impact_name'] ?? 'Shooter',
            'impact_shooter_id' => $row['impact_shooter_id'] ?? null,
            'impact_division' => $row['impact_division'] ?? null,
            'impacts' => $row['impacts'] ?? null,
            'match_status' => $row['match_status'] ?? null,
            'reason' => $reason,
            'registration_status' => $extra['registration_status'] ?? ($row['registration_status'] ?? null),
            'payment_status' => $extra['payment_status'] ?? ($row['payment_status'] ?? null),
            'fee_amount' => $extra['fee_amount'] ?? 0,
            'saprf_fee' => $extra['saprf_fee'] ?? 0,
            'platform_fee' => $extra['platform_fee'] ?? 0,
        ], $extra);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{pending_unpaid:int,walk_in_member:int,assign_later:int,skipped_fuzzy:int,skipped:int,non_member_stub:int}
     */
    private function summarizeAudit(array $rows): array
    {
        $keys = [
            'pending_unpaid', 'walk_in_member', 'assign_later',
            'skipped_fuzzy', 'skipped', 'non_member_stub',
        ];
        $out = array_fill_keys($keys, 0);
        foreach ($rows as $row) {
            $reason = (string) ($row['reason'] ?? '');
            if (isset($out[$reason])) {
                $out[$reason]++;
            }
        }

        return $out;
    }

    private function createAssignLaterStub(string $name): User
    {
        $slug = Str::slug($name, '.') ?: 'shooter';
        $user = User::create([
            'name' => $name,
            'email' => 'impact.assignlater.'.$slug.'.'.Str::lower(Str::random(6)).'@saprf.walkin',
            'password' => Hash::make(Str::random(40)),
        ]);
        if (method_exists($user, 'assignRole')) {
            try {
                $user->assignRole('member');
            } catch (\Throwable) {
                // role may not exist in partial test contexts
            }
        }

        return $user;
    }

    private function maybeDeleteAssignLaterStub(?int $userId): void
    {
        if (! $userId) {
            return;
        }

        $user = User::query()->find($userId);
        if (! $user || ! str_contains((string) $user->email, '@saprf.walkin')) {
            return;
        }
        $email = (string) $user->email;
        if (! str_contains($email, 'impact.assignlater.') && ! str_contains($email, 'csv.assignlater.')) {
            return;
        }
        if (Score::query()->where('user_id', $userId)->exists()) {
            return;
        }

        try {
            $user->delete();
        } catch (\Throwable) {
            // leave orphan stub if FK prevents delete
        }
    }

    /**
     * @param  array<int, list<int>>  $idsByDay
     * @return list<array{day:int,count:int}>
     */
    private function stageDaySummary(array $idsByDay): array
    {
        $summary = [];
        foreach ($idsByDay as $day => $ids) {
            $summary[] = ['day' => (int) $day, 'count' => count($ids)];
        }

        return $summary;
    }

    /**
     * @param  list<array{id:int,hits:float}>  $stages
     * @param  list<int>  $stageIds
     */
    public function sumStages(array $stages, array $stageIds): float
    {
        if ($stageIds === []) {
            return 0.0;
        }
        $want = array_flip($stageIds);
        $sum = 0.0;
        foreach ($stages as $stage) {
            if (isset($want[(int) ($stage['id'] ?? 0)])) {
                $sum += (float) ($stage['hits'] ?? $stage['points'] ?? 0);
            }
        }

        return $sum;
    }

    private function resolveDivisionSlug(?MatchRegistration $reg, string $impactDivision): string
    {
        if ($reg?->division?->slug) {
            return $reg->division->slug;
        }

        $key = Str::lower(trim($impactDivision));

        return self::DIVISION_ALIASES[$key] ?? 'open';
    }

    private function divisionIdFromSlug(string $slug): ?int
    {
        return Division::query()->where('slug', $slug)->value('id');
    }

    private function classifyImpactShooter(string $name, ?MatchRegistration $reg): string
    {
        if ($reg?->user) {
            $cat = $this->membership->classifyRegistrationCategory(
                $reg->user,
                $reg->match?->match_date ?? now()
            );

            return $cat === 'active_member' ? 'member' : $cat;
        }

        $user = User::query()
            ->whereRaw('LOWER(name) = ?', [Str::lower(trim($name))])
            ->first();

        if (! $user) {
            return 'unknown';
        }

        $cat = $this->membership->classifyRegistrationCategory($user, now());

        return $cat === 'active_member' ? 'member' : $cat;
    }

    private function previewAction(?MatchRegistration $reg, string $membershipKind): string
    {
        if ($reg) {
            if (! in_array($reg->payment_status, ['paid', 'waived'], true)) {
                return 'import_and_collect';
            }

            return 'import';
        }

        return match ($membershipKind) {
            'member', 'active_member' => 'walk_in_member',
            'lapsed_member' => 'walk_in_lapsed',
            'non_member' => 'non_member_no_log',
            default => 'non_member_no_log',
        };
    }

    private function needsFeeCollection(MatchRegistration $registration): bool
    {
        if (in_array($registration->payment_status, ['paid', 'waived'], true)) {
            return false;
        }

        // Pending registration or unpaid payment
        return true;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{user_id:?int,division_id:?int,collection?:array}
     */
    private function resolveUnmatchedShooter(MatchEvent $match, array $row, User $actor): array
    {
        $divisionSlug = $row['division_slug'] ?: 'open';
        $divisionId = $this->divisionIdFromSlug($divisionSlug);
        $name = $row['impact_name'];

        $existingUser = User::query()
            ->whereRaw('LOWER(name) = ?', [Str::lower($name)])
            ->first();

        $isMember = false;
        if ($existingUser) {
            $isMember = $this->membership->isUserValidForOfficialPurposes(
                $existingUser,
                $match->match_date ?? now()
            );
        }

        if ($isMember && $existingUser) {
            $reg = $this->walkIns->confirmWalkIn($match, [
                'name' => $name,
                'email' => $existingUser->email,
                'division_slug' => $divisionSlug,
                'membership_status' => 'active_member',
                'note' => 'Impact live import — member not on entry list; SAPRF + platform deducted from MD payout.',
            ], $actor);

            return [
                'user_id' => $reg->user_id,
                'division_id' => $reg->division_id,
                'collection' => [
                    'name' => $name,
                    'reason' => 'walk_in_member',
                    'registration_status' => $reg->registration_status,
                    'payment_status' => $reg->payment_status,
                    'fee_amount' => (float) $reg->fee_amount,
                    'saprf_fee' => (float) $reg->saprf_fee,
                    'platform_fee' => (float) $reg->platform_fee,
                ],
            ];
        }

        // Non-member (or unknown): shadow user, no walk-in fees, score won't count to logs
        $user = $existingUser;
        if (! $user) {
            $slug = Str::slug($name, '.') ?: 'shooter';
            $user = User::create([
                'name' => $name,
                'email' => 'impact.'.$slug.'.'.Str::lower(Str::random(6)).'@saprf.walkin',
                'password' => Hash::make(Str::random(40)),
            ]);
            if (method_exists($user, 'assignRole')) {
                try {
                    $user->assignRole('member');
                } catch (\Throwable) {
                    // role may not exist in partial test contexts
                }
            }
        }

        return [
            'user_id' => $user->id,
            'division_id' => $divisionId,
        ];
    }

    private function autoCompleteMatch(MatchEvent $match): void
    {
        if (in_array($match->status, ['completed', 'cancelled'], true)) {
            return;
        }

        $effectiveEnd = $match->match_end_date ?? $match->match_date;
        if ($effectiveEnd === null || $effectiveEnd->isFuture()) {
            return;
        }

        $match->update(['status' => 'completed']);
    }
}
