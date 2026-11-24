<?php

namespace App\Services;

use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\MatchSurveyRespondent;
use App\Models\MatchSurveyResponse;
use App\Models\Score;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Anonymous post-match survey.
 *
 * Two stores by design — same split as the Decision Register:
 *   - match_survey_respondents  — which user replied (never what they said)
 *   - match_survey_responses    — what they said (never which user)
 *
 * Writes happen inside one transaction with the respondent row written
 * first. If the respondent row already exists, the answer row is never
 * added — a shooter can only reply once.
 *
 * Only `provincial`, `national`, and `final` (championship) matches
 * have a survey. Club levels (`club_only`, `club_provincial`) don't.
 *
 * Questions follow the WhatsApp-approved shooter feedback scorecard:
 * 13 strict 1–10 ratings plus three free-text questions (favourite
 * aspect, one thing to improve, other comments). The design is
 * deliberately simple so the same form works for both PRS and PR22.
 *
 * ── Privacy principle ─────────────────────────────────────────────
 *
 * Aggregate results (averages, histograms, comments) are visible only
 * to the match director, Exco, admin, owner and developer roles via
 * the staff results page and the PDF closing report. They are NOT
 * exposed on `events.show`, in any public API, or in any shooter-facing
 * view.
 *
 * This is deliberate. Our match directors are volunteers running
 * matches at their own cost of time and money. Publishing a public
 * score-out-of-10 for their match turns one bad weekend (weather,
 * logistics, a single difficult shooter) into a permanent reputational
 * hit and shrinks the pipeline of people willing to host. The survey
 * exists so the MD gets honest feedback to improve the next match,
 * not so the SAPRF community scores volunteers in public.
 *
 * If a future request asks for "just the overall average publicly"
 * or "top-rated category publicly", push back — that is the exact
 * refactor this comment is here to prevent.
 */
class MatchSurveyService
{
    /**
     * Series levels that get a post-match survey. Everything else — club
     * matches, international placeholders — is silently excluded so the
     * UI never offers a survey where there isn't one.
     *
     * @var list<string>
     */
    public const APPLICABLE_SERIES_LEVELS = ['provincial', 'national', 'final'];

    /**
     * Days after the final match day that the survey window stays open,
     * measured from `match_end_date` (or `match_date` on a one-day match).
     * Short on purpose — shooter recall is best in the first week, and a
     * tight window drives a bigger same-week response spike instead of a
     * trickle over three weeks.
     */
    public const DAYS_AFTER_MATCH_END = 5;

    /**
     * Rating floor / ceiling. The WhatsApp-approved scorecard uses 1–10:
     * 1 = Terrible, 5 = Average, 10 = Excellent / Perfect. All 13 rating
     * rows use the same scale with no NA path — a shooter is expected to
     * answer every row.
     */
    public const RATING_MIN = 1;

    public const RATING_MAX = 10;

    // ── Eligibility ─────────────────────────────────────────────────────

    public function isApplicableTo(MatchEvent $match): bool
    {
        return in_array($match->series_level, self::APPLICABLE_SERIES_LEVELS, true)
            && $match->status === 'completed';
    }

    public function windowClosesAt(MatchEvent $match): ?Carbon
    {
        if (! $this->isApplicableTo($match)) {
            return null;
        }

        $endDate = $match->match_end_date ?: $match->match_date;
        if ($endDate === null) {
            return null;
        }

        return $endDate->copy()->endOfDay()->addDays(self::DAYS_AFTER_MATCH_END);
    }

    public function windowIsOpen(MatchEvent $match): bool
    {
        $closesAt = $this->windowClosesAt($match);

        return $closesAt !== null && now()->lt($closesAt);
    }

    /**
     * IDs of users who may answer: everyone with a non-cancelled entry on
     * this match OR a score row linked by `user_id` (walk-ins the MD
     * mapped onto a member during score import).
     *
     * @return Collection<int, int>
     */
    public function eligibleUserIds(MatchEvent $match): Collection
    {
        $fromEntries = MatchRegistration::query()
            ->where('match_id', $match->id)
            ->where('registration_status', '!=', 'cancelled')
            ->whereNotNull('user_id')
            ->pluck('user_id');

        $fromScores = Score::query()
            ->where('match_id', $match->id)
            ->whereNotNull('user_id')
            ->pluck('user_id');

        return $fromEntries->concat($fromScores)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
    }

    public function userMayAnswer(MatchEvent $match, User $user): bool
    {
        if (! $this->isApplicableTo($match) || ! $this->windowIsOpen($match)) {
            return false;
        }

        if ($this->userHasAnswered($match, $user)) {
            return false;
        }

        return $this->eligibleUserIds($match)->contains($user->id);
    }

    public function userHasAnswered(MatchEvent $match, User $user): bool
    {
        return MatchSurveyRespondent::query()
            ->where('match_id', $match->id)
            ->where('user_id', $user->id)
            ->exists();
    }

    // ── Submit ──────────────────────────────────────────────────────────

    /**
     * Record one anonymous reply for a user. Returns true when a new
     * answer row was written; false when the user had already replied
     * (idempotent no-op, no second answer row is created).
     *
     * @param  array<string, mixed>  $answers
     */
    public function submit(MatchEvent $match, User $user, array $answers): bool
    {
        if (! $this->isApplicableTo($match)) {
            throw new InvalidArgumentException('The post-match survey is only available on provincial, national, or championship matches.');
        }

        if (! $this->windowIsOpen($match)) {
            throw new RuntimeException('The post-match survey is closed.');
        }

        if (! $this->eligibleUserIds($match)->contains($user->id)) {
            throw new RuntimeException('Only shooters who entered or shot this match may fill in the survey.');
        }

        return DB::transaction(function () use ($match, $user, $answers): bool {
            // The INSERT IGNORE (via insertOrIgnore) is the real one-reply
            // gate. It is atomic against a composite primary key, so two
            // concurrent submits from the same shooter cannot both pass.
            $inserted = MatchSurveyRespondent::query()->insertOrIgnore([
                'match_id' => $match->id,
                'user_id' => $user->id,
            ]);

            if ($inserted === 0) {
                return false;
            }

            MatchSurveyResponse::create([
                'match_id' => $match->id,
                'answers' => $answers,
            ]);

            return true;
        });
    }

    // ── Aggregates (staff view) ─────────────────────────────────────────

    /**
     * Headline numbers + per-question breakdowns for the Exco results
     * tab. Returns no shooter names and no submission timestamps — the
     * caller only sees "how many answered" and "what they said".
     *
     * Histograms are keyed 1..10 to match the rating scale.
     *
     * @return array{
     *   responded: int,
     *   eligible: int,
     *   response_rate: ?float,
     *   ratings: array<string, array{count: int, average: ?float, histogram: array<int, int>}>,
     *   comments: array<string, list<string>>,
     * }
     */
    public function aggregates(MatchEvent $match): array
    {
        $eligible = $this->eligibleUserIds($match)->count();
        $responded = MatchSurveyRespondent::query()->where('match_id', $match->id)->count();
        $rate = $eligible > 0 ? round(($responded / $eligible) * 100, 1) : null;

        $responses = MatchSurveyResponse::query()
            ->where('match_id', $match->id)
            ->get()
            ->pluck('answers');

        $ratings = [];
        $commentKeys = self::commentKeys();
        $comments = array_fill_keys($commentKeys, []);

        $blankHistogram = [];
        for ($i = self::RATING_MIN; $i <= self::RATING_MAX; $i++) {
            $blankHistogram[$i] = 0;
        }

        foreach (self::ratingKeys() as $key) {
            $ratings[$key] = [
                'count' => 0,
                'average' => null,
                'histogram' => $blankHistogram,
            ];
        }

        $sums = array_fill_keys(array_keys($ratings), 0);

        foreach ($responses as $answers) {
            if (! is_array($answers)) {
                continue;
            }

            foreach ($ratings as $key => $_ignore) {
                $raw = $answers[$key] ?? null;
                if (! is_numeric($raw)) {
                    continue;
                }
                $value = (int) $raw;
                if ($value < self::RATING_MIN || $value > self::RATING_MAX) {
                    continue;
                }
                $ratings[$key]['histogram'][$value]++;
                $ratings[$key]['count']++;
                $sums[$key] += $value;
            }

            foreach ($commentKeys as $textKey) {
                $text = trim((string) ($answers[$textKey] ?? ''));
                if ($text !== '') {
                    $comments[$textKey][] = $text;
                }
            }
        }

        foreach ($ratings as $key => $row) {
            if ($row['count'] > 0) {
                $ratings[$key]['average'] = round($sums[$key] / $row['count'], 2);
            }
        }

        // Shuffle comments so reading order doesn't match submission
        // order — another small guard on anonymity when response counts
        // are low enough to make submission-order guessing plausible.
        foreach ($comments as $key => $list) {
            shuffle($comments[$key]);
        }

        return [
            'responded' => $responded,
            'eligible' => $eligible,
            'response_rate' => $rate,
            'ratings' => $ratings,
            'comments' => $comments,
        ];
    }

    // ── Question keys / shape (used by controller + views) ──────────────

    /**
     * Rating keys (1–10). Order matches the WhatsApp-approved scorecard
     * numbering so what the shooter sees on the form lines up with the
     * numbering in the briefing announcement.
     *
     * @return list<string>
     */
    public static function ratingKeys(): array
    {
        return [
            'comms_before',    // 1. Pre-match communication
            'zero_range',      // 2. Zeroing and dope facilities
            'matchbook',       // 3. Matchbook quality and accuracy
            'briefings',       // 4. Match and stage briefings
            'stage_design',    // 5. Stage design
            'target_sizes',    // 6. Target sizes
            'difficulty',      // 7. Overall match difficulty
            'range_officers',  // 8. Range officers and scorers
            'timekeeping',     // 9. Timekeeping and match efficiency
            'catering',        // 10. Food and catering
            'toilets',         // 11. Toilet facilities
            'prize_giving',    // 12. Prizegiving
            'trophies',        // 13. Trophies
        ];
    }

    /**
     * Comment field keys and whether a non-empty answer is required.
     * The third field ("any other comments") is deliberately optional —
     * Q14 and Q15 pin down the headline reactions, Q16 is a catch-all.
     *
     * @return array<string, bool> keyed by field name, true = required
     */
    public static function commentFields(): array
    {
        return [
            'highlight' => true,       // 14. Favourite aspect
            'improvement' => true,     // 15. One thing to improve
            'other_comments' => false, // 16. Any other comments
        ];
    }

    /**
     * Flat list of comment keys in display order (for the aggregates
     * loop and the results view).
     *
     * @return list<string>
     */
    public static function commentKeys(): array
    {
        return array_keys(self::commentFields());
    }

    public static function maxCommentLength(): int
    {
        return 500;
    }
}
