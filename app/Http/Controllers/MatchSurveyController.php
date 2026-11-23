<?php

namespace App\Http\Controllers;

use App\Models\MatchEvent;
use App\Models\User;
use App\Notifications\MatchSurveyInvitationNotification;
use App\Services\MatchSurveyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * Anonymous post-match survey endpoints.
 *
 * The form (`show` + `store`) is member-facing: logged-in shooters who
 * entered or shot the match can answer once.
 *
 * The `results` + `invite` endpoints are staff-only (Exco / Owner /
 * Admin / Developer). Match directors are NOT allowed to read answers
 * because the free-text comments cover the RO team, the range setup
 * and SAPRF comms — a names-attached results page would undo the
 * anonymity the two-table split exists to protect.
 */
class MatchSurveyController extends Controller
{
    private const SUBMIT_RATE_LIMIT_MAX = 5;

    private const SUBMIT_RATE_LIMIT_DECAY_SECONDS = 60;

    public function __construct(
        private readonly MatchSurveyService $surveys,
    ) {}

    // ── Shooter: the form ──────────────────────────────────────────────

    public function show(Request $request, MatchEvent $match): View|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $this->surveys->isApplicableTo($match)) {
            return redirect()
                ->route('events.show', $match)
                ->with('error', 'This match does not have a post-match survey.');
        }

        if (! $this->surveys->windowIsOpen($match)) {
            return redirect()
                ->route('events.show', $match)
                ->with('error', 'The post-match survey for this match is closed.');
        }

        if (! $this->surveys->eligibleUserIds($match)->contains($user->id)) {
            abort(403, 'Only shooters who entered or shot this match may fill in the survey.');
        }

        if ($this->surveys->userHasAnswered($match, $user)) {
            return redirect()
                ->route('events.show', $match)
                ->with('success', 'Thanks — you have already answered the survey for this match.');
        }

        return view('matches.survey.form', [
            'match' => $match,
            'closesAt' => $this->surveys->windowClosesAt($match),
        ]);
    }

    public function store(Request $request, MatchEvent $match): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $this->surveys->isApplicableTo($match)) {
            return redirect()
                ->route('events.show', $match)
                ->with('error', 'This match does not have a post-match survey.');
        }

        if (! $this->surveys->windowIsOpen($match)) {
            return redirect()
                ->route('events.show', $match)
                ->with('error', 'The post-match survey for this match is closed.');
        }

        if ($request->session()->has('impersonator_id')) {
            return redirect()
                ->route('events.show', $match)
                ->with('error', 'Submitting the survey is disabled while impersonating a member.');
        }

        $limitKey = 'match-survey:'.$user->id;
        if (RateLimiter::tooManyAttempts($limitKey, self::SUBMIT_RATE_LIMIT_MAX)) {
            return redirect()
                ->route('matches.survey.show', $match)
                ->with('error', 'Too many submit attempts. Please wait a moment and try again.');
        }
        RateLimiter::hit($limitKey, self::SUBMIT_RATE_LIMIT_DECAY_SECONDS);

        $data = $this->validatedAnswers($request);

        try {
            $this->surveys->submit($match, $user, $data);
        } catch (RuntimeException $e) {
            return redirect()
                ->route('matches.survey.show', $match)
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('events.show', $match)
            ->with('success', 'Thanks — your anonymous feedback has been recorded.');
    }

    // ── Staff: results tab + invite action ─────────────────────────────

    public function results(Request $request, MatchEvent $match): View|RedirectResponse
    {
        $this->authorizeStaff($request, $match);

        if (! $this->surveys->isApplicableTo($match)) {
            return redirect()
                ->route('matches.overview', $match)
                ->with('error', 'The post-match survey is only available on provincial, national, or championship matches.');
        }

        // Reuse the workspace chrome so the Survey tab sits next to the
        // other match tabs. Tabs are rebuilt here instead of pulled from
        // MatchWorkspaceController because that controller's `tabsFor`
        // is private — by the time we get here the tab list is known
        // for sure (Exco viewer + applicable match), so inline it.
        $tabs = [
            ['key' => 'overview', 'label' => 'Overview', 'route' => 'matches.overview', 'count' => null],
            ['key' => 'entries', 'label' => 'Entries', 'route' => 'matches.entries', 'count' => null],
            ['key' => 'scores', 'label' => 'Scores', 'route' => 'matches.scores', 'count' => null],
            ['key' => 'finances', 'label' => 'Finances', 'route' => 'matches.finances', 'count' => null],
            ['key' => 'messages', 'label' => 'Messages', 'route' => 'matches.messages', 'count' => null],
            ['key' => 'settings', 'label' => 'Settings', 'route' => 'matches.settings', 'count' => null],
            ['key' => 'survey', 'label' => 'Survey', 'route' => 'matches.survey.results', 'count' => null],
        ];

        return view('matches.survey.results', [
            'match' => $match,
            'aggregates' => $this->surveys->aggregates($match),
            'closesAt' => $this->surveys->windowClosesAt($match),
            'windowIsOpen' => $this->surveys->windowIsOpen($match),
            'tabs' => $tabs,
            'activeTab' => 'survey',
            'counts' => [],
            'stageContext' => $match->stageContext(),
        ]);
    }

    public function invite(Request $request, MatchEvent $match): RedirectResponse
    {
        $this->authorizeStaff($request, $match);

        if (! $this->surveys->isApplicableTo($match)) {
            return redirect()
                ->route('matches.overview', $match)
                ->with('error', 'The post-match survey is only available on provincial, national, or championship matches.');
        }

        if ($match->survey_invites_sent_at !== null) {
            return redirect()
                ->route('matches.survey.results', $match)
                ->with('error', 'Invites were already sent for this match.');
        }

        $userIds = $this->surveys->eligibleUserIds($match);
        if ($userIds->isEmpty()) {
            return redirect()
                ->route('matches.survey.results', $match)
                ->with('error', 'No eligible shooters to invite.');
        }

        $users = User::query()
            ->whereIn('id', $userIds)
            ->whereNotNull('email_verified_at')
            ->get();

        if ($users->isEmpty()) {
            return redirect()
                ->route('matches.survey.results', $match)
                ->with('error', 'No eligible shooters have a verified email on file.');
        }

        Notification::send($users, new MatchSurveyInvitationNotification($match));

        $match->forceFill(['survey_invites_sent_at' => now()])->save();

        return redirect()
            ->route('matches.survey.results', $match)
            ->with('success', 'Survey invitation sent to '.$users->count().' '
                .($users->count() === 1 ? 'shooter' : 'shooters').'.');
    }

    // ── Helpers ────────────────────────────────────────────────────────

    /**
     * Staff-only gate for results / invite. Match directors can `view` the
     * match in general, but survey results are intentionally hidden from
     * them because the comments talk about ROs and SAPRF comms.
     */
    private function authorizeStaff(Request $request, MatchEvent $match): void
    {
        /** @var User|null $user */
        $user = $request->user();
        if ($user === null || ! $user->hasAnyRole(['developer', 'exco', 'owner', 'admin'])) {
            abort(403, 'Only Exco, owners, and admins can read survey results.');
        }

        // Keep the authorize() call so the policy still has a chance to
        // deny on an unrelated match the staff role should not touch.
        $this->authorize('view', $match);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedAnswers(Request $request): array
    {
        $ratingKeys = MatchSurveyService::ratingKeys();
        $commentFields = MatchSurveyService::commentFields();
        $maxComment = MatchSurveyService::maxCommentLength();

        // Ratings are posted as radio values (strings). The strict 1–10
        // scale is enforced here; there is no NA sentinel — every rating
        // row must carry a number.
        $allowedRatings = [];
        for ($i = MatchSurveyService::RATING_MIN; $i <= MatchSurveyService::RATING_MAX; $i++) {
            $allowedRatings[] = (string) $i;
        }

        $rules = [];
        foreach ($ratingKeys as $key) {
            $rules[$key] = ['required', 'string', Rule::in($allowedRatings)];
        }

        foreach ($commentFields as $key => $required) {
            $base = $required
                ? ['required', 'string', 'min:2', 'max:'.$maxComment]
                : ['nullable', 'string', 'max:'.$maxComment];
            $rules[$key] = $base;
        }

        $data = $request->validate($rules);

        // Normalise ratings to ints so the aggregates query can treat
        // them as numbers and the JSON blob is the right shape.
        foreach ($ratingKeys as $key) {
            $data[$key] = (int) $data[$key];
        }

        foreach (array_keys($commentFields) as $key) {
            $data[$key] = trim((string) ($data[$key] ?? ''));
        }

        return $data;
    }
}
