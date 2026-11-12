<?php

namespace App\Http\Controllers;

use App\Models\Decision;
use App\Services\DecisionReactionService;
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
    ) {}

    public function index(Request $request): View
    {
        $viewer = $request->user();

        $decisions = Decision::query()
            ->published()
            ->with('tally')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate(12)
            ->withQueryString();

        $reactions = [];
        foreach ($decisions as $decision) {
            $reactions[$decision->id] = $this->presenter->forDecision($decision, $viewer);
        }

        return view('decisions.index', [
            'decisions' => $decisions,
            'reactions' => $reactions,
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
            return redirect()
                ->route('decisions.index')
                ->with('error', $e->getMessage());
        }

        // Never flash the direction — the flash only confirms participation.
        return redirect()
            ->route('decisions.index')
            ->with('success', 'You have reacted.');
    }
}
