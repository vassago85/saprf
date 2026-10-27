<?php

namespace App\Services;

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Membership;
use App\Models\Score;
use App\Models\ScoreImport;
use App\Models\ShooterLog;
use App\Models\User;
use App\Services\ImpactScoring\ImpactNameMatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * CSV score import with the same assignment corrections as Impact live:
 * exact entry match, confirm fuzzy names, assign later, skip, or auto
 * walk-in / non-member. Registration division wins. The imported name
 * stays on the scoreboard.
 */
class CsvScoreAssignmentService
{
    public function __construct(
        private readonly ScoreImportService $csv,
        private readonly ImpactNameMatcher $names,
        private readonly MembershipValidationService $membership,
        private readonly WalkInRegistrationService $walkIns,
        private readonly ScoreValidationService $scoreValidation,
        private readonly StandingsCalculationService $standings,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * @return array{shooters:list<array<string,mixed>>,summary:array<string,int>,skipped:list<string>}
     */
    public function preview(MatchEvent $match, string $fullPath): array
    {
        $parsed = $this->csv->rowsFromCsv($fullPath);
        $registrations = $this->registrationsFor($match);
        $candidates = $this->nameCandidates($registrations);

        $shooters = [];
        $skipped = [];

        foreach ($parsed as $item) {
            $row = $item['row'];
            $index = (int) $item['index'];
            $name = trim((string) ($row['shooter_name'] ?? ''));
            if ($name === '') {
                $skipped[] = 'Row '.($index + 2).': missing shooter name';

                continue;
            }
            if (! isset($row['raw_score']) || ! is_numeric($row['raw_score'])) {
                $skipped[] = 'Row '.($index + 2)." ({$name}): non-numeric or missing score";

                continue;
            }

            $linked = $this->registrationByIdentity($row, $registrations);
            if ($linked) {
                $matchResult = [
                    'status' => 'exact',
                    'key' => 'reg:'.$linked->id,
                    'candidates' => [],
                ];
            } else {
                $matchResult = $this->names->match($name, $candidates);
                if ($matchResult['status'] === 'exact' && $matchResult['key']) {
                    $linked = $registrations->first(
                        fn (MatchRegistration $r) => 'reg:'.$r->id === $matchResult['key']
                    );
                }
            }

            $csvSlug = $this->csv->divisionSlugForRow($row) ?: 'open';
            $platformUser = $linked?->user ?: $this->findPlatformUser($row, $name);
            $membershipKind = $this->membershipKind($platformUser, $match);

            $shooters[] = [
                'row_key' => (string) $index,
                'name' => $name,
                'division_slug' => $linked?->division?->slug ?: $csvSlug,
                'division_source' => $linked?->division_id ? 'registration' : 'csv',
                'impacts' => (float) $row['raw_score'],
                'placement' => isset($row['placement']) && is_numeric($row['placement']) ? (int) $row['placement'] : null,
                'match_status' => $matchResult['status'],
                'fuzzy_candidates' => $matchResult['candidates'],
                'registration_id' => $linked?->id,
                'registration_name' => $linked
                    ? ($linked->user?->name ?: $linked->shooter_name)
                    : null,
                'registration_status' => $linked?->registration_status,
                'payment_status' => $linked?->payment_status,
                'membership_kind' => $membershipKind,
                'action' => $this->previewAction($linked, $membershipKind),
                'source_row' => $row,
            ];
        }

        return [
            'shooters' => $shooters,
            'skipped' => $skipped,
            'summary' => [
                'total' => count($shooters),
                'exact' => count(array_filter($shooters, fn ($r) => $r['match_status'] === 'exact')),
                'fuzzy' => count(array_filter($shooters, fn ($r) => $r['match_status'] === 'fuzzy')),
                'unmatched' => count(array_filter($shooters, fn ($r) => $r['match_status'] === 'unmatched')),
            ],
        ];
    }

    /**
     * @param  array{replace_existing?:bool,links?:array<string,string>,score_overrides?:array<string,array{impacts?:mixed}>,notes?:list<string>}  $options
     */
    public function import(
        MatchEvent $selectedMatch,
        MatchEvent $targetMatch,
        User $actor,
        string $fullPath,
        array $options = [],
    ): ScoreImport {
        $preview = $this->preview($selectedMatch, $fullPath);
        /** @var array<string, string> $links */
        $links = $options['links'] ?? [];
        /** @var array<string, array{impacts?:mixed}> $overrides */
        $overrides = $options['score_overrides'] ?? [];

        foreach ($preview['shooters'] as $row) {
            $link = $links[$row['row_key']] ?? null;
            if ($row['match_status'] !== 'fuzzy') {
                continue;
            }
            $confirmed = $link === 'skip'
                || $link === 'assign_later'
                || (is_string($link) && str_starts_with($link, 'reg:'));
            if (! $confirmed) {
                throw new RuntimeException('Confirm every fuzzy name match, or choose Assign later / Skip, before importing.');
            }
        }

        $replaceExisting = (bool) ($options['replace_existing'] ?? false);
        $sourceType = (string) ($options['source_type'] ?? 'csv');
        $originalFilename = $options['original_filename'] ?? null;
        $notes = $options['notes'] ?? [];
        $notes[] = 'CSV import with entry assignment.';

        return DB::transaction(function () use (
            $selectedMatch, $targetMatch, $actor, $preview, $links, $overrides,
            $replaceExisting, $notes, $sourceType, $originalFilename
        ) {
            if ($replaceExisting) {
                $scoreIds = Score::where('match_id', $targetMatch->id)->pluck('id');
                ShooterLog::whereIn('score_id', $scoreIds)->delete();
                ShooterLog::where('match_id', $targetMatch->id)->delete();
                Score::where('match_id', $targetMatch->id)->delete();
                $notes[] = 'Existing scores for the target match were cleared before import.';
            }

            $import = ScoreImport::query()->create([
                'match_id' => $targetMatch->id,
                'uploaded_by' => $actor->id,
                'source_type' => $sourceType,
                'day' => null,
                'original_filename' => $originalFilename,
                'import_status' => 'processing',
                'notes' => implode(' ', $notes),
            ]);

            $auditRows = [];
            $count = 0;

            foreach ($preview['shooters'] as $row) {
                $link = $links[$row['row_key']] ?? null;
                if ($row['match_status'] === 'exact' && ($link === null || $link === '' || $link === 'auto')) {
                    $link = $row['registration_id'] ? 'reg:'.$row['registration_id'] : 'auto';
                }
                if ($row['match_status'] === 'unmatched' && ($link === null || $link === '')) {
                    $link = 'auto';
                }

                if ($link === 'skip') {
                    $auditRows[] = $this->auditRow($row, 'skipped');

                    continue;
                }

                $assignLater = $link === 'assign_later';
                $registration = null;
                if (! $assignLater && is_string($link) && str_starts_with($link, 'reg:')) {
                    $registration = MatchRegistration::query()
                        ->with(['user', 'division'])
                        ->where('match_id', $selectedMatch->id)
                        ->where('id', (int) substr($link, 4))
                        ->first();
                    if (! $registration) {
                        throw new RuntimeException('Entry '.$link.' is not on this match.');
                    }
                }

                $original = (float) $row['impacts'];
                $override = $overrides[$row['row_key']]['impacts'] ?? null;
                $rawScore = is_numeric($override) ? (float) $override : $original;
                $wasAdjusted = abs($rawScore - $original) > 0.0001;

                $needsMemberLink = false;
                $auditReason = null;
                $userId = null;
                $divisionId = $this->divisionId($row['division_slug'] ?: 'open');
                $shooterName = $row['name'];

                if ($assignLater) {
                    $stub = $this->createStub($row['name'], 'csv.assignlater.');
                    $userId = $stub->id;
                    $needsMemberLink = true;
                    $auditReason = 'assign_later';
                    $auditRows[] = $this->auditRow($row, 'assign_later', ['user_id' => $userId]);
                } elseif ($registration) {
                    $userId = $registration->user_id;
                    $divisionId = $registration->division_id ?: $divisionId;
                    if ($this->needsFeeCollection($registration)) {
                        $auditReason = 'pending_unpaid';
                        $auditRows[] = $this->auditRow($row, 'pending_unpaid', [
                            'registration_id' => $registration->id,
                            'registration_status' => $registration->registration_status,
                            'payment_status' => $registration->payment_status,
                            'fee_amount' => (float) $registration->fee_amount,
                            'saprf_fee' => (float) $registration->saprf_fee,
                            'platform_fee' => (float) $registration->platform_fee,
                            'linked_name' => $registration->user?->name ?: $registration->shooter_name,
                        ]);
                    }
                } else {
                    $resolved = $this->resolveUnmatched($selectedMatch, $row, $actor);
                    $userId = $resolved['user_id'];
                    $divisionId = $resolved['division_id'] ?: $divisionId;
                    if ($resolved['collection'] ?? null) {
                        $auditReason = 'walk_in_member';
                        $auditRows[] = array_merge($this->auditRow($row, 'walk_in_member'), $resolved['collection']);
                    } else {
                        $auditReason = 'non_member_stub';
                        $auditRows[] = $this->auditRow($row, 'non_member_stub', ['user_id' => $userId]);
                    }
                }

                $score = new Score();
                $score->fill([
                    'match_id' => $targetMatch->id,
                    'score_import_id' => $import->id,
                    'shooter_name' => $shooterName,
                    'user_id' => $userId,
                    'placement' => $row['placement'],
                    'division_id' => $divisionId,
                    'status' => 'pending',
                    'is_member' => false,
                    'match_date' => $targetMatch->match_date,
                    'day1_raw_score' => $rawScore,
                    'raw_meta' => [
                        'day_1' => $row['source_row'],
                        'assignment' => [
                            'source' => 'csv',
                            'needs_member_link' => $needsMemberLink,
                            'adjusted' => $wasAdjusted,
                            'original_impacts' => $original,
                        ],
                        'impact' => $needsMemberLink ? [
                            'needs_member_link' => true,
                            'impact_name' => $shooterName,
                        ] : [
                            'needs_member_link' => false,
                        ],
                    ],
                ]);
                if ($auditReason) {
                    $meta = $score->raw_meta;
                    $meta['assignment']['audit_reason'] = $auditReason;
                    $score->raw_meta = $meta;
                }
                $score->save();
                $this->scoreValidation->evaluateScoreStatus($score);
                $count++;
            }

            $summaryKeys = ['pending_unpaid', 'walk_in_member', 'assign_later', 'skipped', 'non_member_stub'];
            $auditSummary = array_fill_keys($summaryKeys, 0);
            foreach ($auditRows as $auditRow) {
                $reason = (string) ($auditRow['reason'] ?? '');
                if (isset($auditSummary[$reason])) {
                    $auditSummary[$reason]++;
                }
            }

            $summary = "Imported {$count} rows with entry assignment.";
            if ($preview['skipped'] !== []) {
                $summary .= ' Skipped '.count($preview['skipped']).' rows:'.PHP_EOL.implode(PHP_EOL, $preview['skipped']);
            }
            if ($auditRows !== []) {
                $summary .= ' Audit: unpaid '.$auditSummary['pending_unpaid']
                    .', walk-ins '.$auditSummary['walk_in_member']
                    .', assign-later '.$auditSummary['assign_later']
                    .', skipped '.$auditSummary['skipped']
                    .', non-members '.$auditSummary['non_member_stub'].'.';
            }

            $import->update([
                'import_status' => 'completed',
                'notes' => trim(($import->notes ?? '').PHP_EOL.$summary),
                'raw_payload' => [
                    'source' => 'csv_assignment',
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
                'score.import.csv_assigned',
                'ScoreImport',
                $import->id,
                null,
                [
                    'row_count' => $count,
                    'audit_count' => count($auditRows),
                ]
            );

            return $import->fresh();
        });
    }

    /**
     * @return \Illuminate\Support\Collection<int, MatchRegistration>
     */
    private function registrationsFor(MatchEvent $match)
    {
        return $match->registrations()
            ->with(['user.membership', 'division:id,name,slug'])
            ->where(function ($q) {
                $q->whereNull('registration_status')
                    ->orWhereNotIn('registration_status', ['cancelled']);
            })
            ->get();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, MatchRegistration>  $registrations
     * @return list<array{key:string,name:string}>
     */
    private function nameCandidates($registrations): array
    {
        return $registrations->map(fn (MatchRegistration $r) => [
            'key' => 'reg:'.$r->id,
            'name' => $r->user?->name ?: (string) $r->shooter_name,
        ])->values()->all();
    }

    /**
     * Email or SAPRF number pins a row to an entry before name matching.
     *
     * @param  \Illuminate\Support\Collection<int, MatchRegistration>  $registrations
     */
    private function registrationByIdentity(array $row, $registrations): ?MatchRegistration
    {
        $email = Str::lower(trim((string) ($row['email'] ?? '')));
        if ($email !== '' && ! Str::endsWith($email, '@managed.saprf.co.za')) {
            $hit = $registrations->first(function (MatchRegistration $r) use ($email) {
                $candidates = array_filter([
                    Str::lower((string) $r->user?->email),
                    Str::lower((string) $r->email),
                ]);

                return in_array($email, $candidates, true);
            });
            if ($hit) {
                return $hit;
            }
        }

        $memberNumber = trim((string) ($row['member_number'] ?? $row['saprf_number'] ?? ''));
        if ($memberNumber === '') {
            return null;
        }

        $stripped = ltrim($memberNumber, '0');

        return $registrations->first(function (MatchRegistration $r) use ($memberNumber, $stripped) {
            $number = (string) ($r->user?->membership?->saprf_number ?? '');
            if ($number === '') {
                return false;
            }
            if (strcasecmp($number, $memberNumber) === 0) {
                return true;
            }

            return $stripped !== '' && ctype_digit($stripped) && Str::endsWith($number, '-'.$stripped);
        });
    }

    private function findPlatformUser(array $row, string $name): ?User
    {
        $email = Str::lower(trim((string) ($row['email'] ?? '')));
        if ($email !== '' && ! Str::endsWith($email, '@managed.saprf.co.za')) {
            $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
            if ($user) {
                return $user;
            }
        }

        $memberNumber = trim((string) ($row['member_number'] ?? ''));
        if ($memberNumber !== '') {
            $userId = Membership::query()->where('saprf_number', $memberNumber)->value('user_id');
            if (! $userId) {
                $stripped = ltrim($memberNumber, '0');
                if ($stripped !== '' && ctype_digit($stripped)) {
                    $userId = Membership::query()->where('saprf_number', 'like', '%-'.$stripped)->value('user_id');
                }
            }
            if ($userId) {
                return User::query()->find($userId);
            }
        }

        if ($name === '') {
            return null;
        }

        return User::query()->whereRaw('LOWER(name) = ?', [Str::lower($name)])->first();
    }

    private function membershipKind(?User $user, MatchEvent $match): string
    {
        if (! $user) {
            return 'unknown';
        }

        $cat = $this->membership->classifyRegistrationCategory($user, $match->match_date ?? now());

        return $cat === 'active_member' ? 'member' : $cat;
    }

    private function previewAction(?MatchRegistration $reg, string $membershipKind): string
    {
        if ($reg) {
            return $this->needsFeeCollection($reg) ? 'import_and_collect' : 'import';
        }

        return match ($membershipKind) {
            'member', 'active_member' => 'walk_in_member',
            'lapsed_member' => 'walk_in_lapsed',
            default => 'non_member_no_log',
        };
    }

    private function needsFeeCollection(MatchRegistration $registration): bool
    {
        return ! in_array($registration->payment_status, ['paid', 'waived'], true);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{user_id:?int,division_id:?int,collection?:array<string,mixed>}
     */
    private function resolveUnmatched(MatchEvent $match, array $row, User $actor): array
    {
        $divisionSlug = $row['division_slug'] ?: 'open';
        $divisionId = $this->divisionId($divisionSlug);
        $name = $row['name'];
        $source = $row['source_row'] ?? [];
        $existing = $this->findPlatformUser(is_array($source) ? $source : [], $name);
        $isMember = $existing && $this->membership->isUserValidForOfficialPurposes(
            $existing,
            $match->match_date ?? now()
        );

        if ($isMember && $existing) {
            $reg = $this->walkIns->confirmWalkIn($match, [
                'name' => $name,
                'email' => $existing->email,
                'division_slug' => $divisionSlug,
                'membership_status' => 'active_member',
                'note' => 'CSV import — member not on the entry list.',
            ], $actor);

            return [
                'user_id' => $reg->user_id,
                'division_id' => $reg->division_id,
                'collection' => [
                    'name' => $name,
                    'reason' => 'walk_in_member',
                    'registration_id' => $reg->id,
                    'registration_status' => $reg->registration_status,
                    'payment_status' => $reg->payment_status,
                    'fee_amount' => (float) $reg->fee_amount,
                    'saprf_fee' => (float) $reg->saprf_fee,
                    'platform_fee' => (float) $reg->platform_fee,
                ],
            ];
        }

        $user = $existing ?: $this->createStub($name, 'csv.');

        return [
            'user_id' => $user->id,
            'division_id' => $divisionId,
        ];
    }

    private function createStub(string $name, string $prefix): User
    {
        $slug = Str::slug($name, '.') ?: 'shooter';
        $user = User::create([
            'name' => $name,
            'email' => $prefix.$slug.'.'.Str::lower(Str::random(6)).'@saprf.walkin',
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

    private function divisionId(string $slug): ?int
    {
        return Division::query()->where('slug', $slug)->value('id');
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function auditRow(array $row, string $reason, array $extra = []): array
    {
        return array_merge([
            'name' => $row['name'] ?? 'Shooter',
            'impacts' => $row['impacts'] ?? null,
            'match_status' => $row['match_status'] ?? null,
            'reason' => $reason,
            'registration_status' => $row['registration_status'] ?? null,
            'payment_status' => $row['payment_status'] ?? null,
            'fee_amount' => 0,
        ], $extra);
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

        $previous = $match->status;
        $match->update(['status' => 'completed']);

        $this->audit->log(
            auth()->user(),
            'match.auto_completed_after_import',
            'MatchEvent',
            $match->id,
            ['status' => $previous],
            ['status' => 'completed'],
        );
    }
}
