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
 * The `results` + `invite` endpoints are match-director + staff only —
 * Match Director, Exco, Owner, Admin, and Developer can both open the
 * results page and fire the invite email. The anonymity split still
 * holds end-to-end: both the pre-open invite (this controller) and the
 * post-close report (SendClosedMatchSurveyReportsJob) show aggregates
 * and comments, never per-shooter answers.
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

    // ── MD + staff: results tab + invite action ────────────────────────

    public function results(Request $request, MatchEvent $match): View|RedirectResponse
    {
        $this->authorizeSurveyAccess($request, $match);

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
        $this->authorizeSurveyAccess($request, $match);

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
     * Gate for the results page + invite action.
     *
     * Match Directors can open the results page and fire the invite for
     * their own match — the MatchPolicy::view() call on the same
     * controller already limits a match_director to matches they
     * created, so a match_director cannot sideload another MD's match.
     *
     * Exco / Owner / Admin / Developer get the full picture across every
     * match as before. "member" role on its own is never enough.
     */
    private function authorizeSurveyAccess(Request $request, MatchEvent $match): void
    {
        /** @var User|null $user */
        $user = $request->user();
        if ($user === null || ! $user->hasAnyRole(['developer', 'exco', 'owner', 'admin', 'match_director'])) {
            abort(403, 'Only the match director, Exco, owners, and admins can open the survey page.');
        }

        // Per-match scoping: match_director must own this match, everyone
        // else is already blanket-allowed by the policy.
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
