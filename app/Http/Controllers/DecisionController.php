<?php

namespace App\Http\Controllers;

use App\Models\Decision;
use App\Services\DecisionReactionService;
use App\Services\DecisionResultsNotifier;
use App\Support\DecisionNumberer;
use App\Support\DecisionReactionPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use RuntimeException;

class DecisionController extends Controller
{
    private const RATE_LIMIT_MAX = 10;

    private const RATE_LIMIT_DECAY_SECONDS = 60;

    public function __construct(
        private readonly DecisionReactionPresenter $presenter,
        private readonly DecisionReactionService $reactions,
        private readonly DecisionNumberer $numberer,
        private readonly DecisionResultsNotifier $resultsNotifier,
    ) {}

    public function index(Request $request): View
    {
        $viewer = $request->user();

        // Catch anything the hourly job has not flipped yet, then email
        // ExCo + voters any pending close digests.
        Decision::query()
            ->published()
            ->whereNotNull('published_at')
            ->orderBy('id')
            ->each(fn (Decision $decision) => $decision->archiveIfVotingClosed());
        $this->resultsNotifier->sendPending();

        $decisions = Decision::query()
            ->onRegister()
            ->with('tally')
            ->orderByRaw("CASE status WHEN 'published' THEN 0 ELSE 1 END")
            ->orderByDesc('published_at')
            ->orderBy('id')
            ->paginate(12)
            ->withQueryString();

        $reactions = [];
        foreach ($decisions as $decision) {
            $reactions[$decision->id] = $this->presenter->forDecision($decision, $viewer);
        }

        $numbers = $this->numberer->label($decisions->getCollection());

        return view('decisions.index', [
            'decisions' => $decisions,
            'reactions' => $reactions,
            'numbers' => $numbers,
        ]);
    }

    public function react(Request $request, Decision $decision): RedirectResponse
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->guest(route('login'));
        }

        if ($request->session()->has('impersonator_id')) {
            return redirect()
                ->route('decisions.index')
                ->with('error', 'Reactions are disabled while impersonating a member.');
        }

        $data = $request->validate([
            'reaction' => ['required', 'in:like,dislike,neutral'],
        ]);

        $limitKey = 'decision-reaction:'.$user->id;
        if (RateLimiter::tooManyAttempts($limitKey, self::RATE_LIMIT_MAX)) {
            return redirect()
                ->route('decisions.index')
                ->with('error', 'Too many reaction attempts. Please wait a moment and try again.');
        }
        RateLimiter::hit($limitKey, self::RATE_LIMIT_DECAY_SECONDS);

        if (! $decision->isPublished()) {
            abort(404);
        }

        try {
            $this->reactions->react($decision, $user, $data['reaction']);
        } catch (RuntimeException $e) {
            // Error banner lives at the top of the page, so leave the scroll
            // position at the top for errors.
            return redirect()
                ->route('decisions.index')
                ->with('error', $e->getMessage());
        }

        // Never flash the direction — the flash only confirms participation.
        // Keep the viewer on the decision they voted on so the inline
        // "You have reacted" lands right under their eye.
        return redirect(route('decisions.index').'#decision-'.$decision->id)
            ->with('success', 'You have reacted.');
    }
}
