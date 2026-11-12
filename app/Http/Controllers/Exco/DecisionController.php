<?php

namespace App\Http\Controllers\Exco;

use App\Enums\DecisionStatus;
use App\Http\Controllers\Controller;
use App\Models\Decision;
use App\Models\DecisionTally;
use App\Services\DecisionPublishNotifier;
use App\Services\SettingsService;
use App\Support\DecisionReactionPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DecisionController extends Controller
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly DecisionPublishNotifier $publishNotifier,
    ) {}

    public function index(): View
    {
        $this->authorize('viewAny', Decision::class);

        $decisions = Decision::query()
            ->with(['tally', 'creator:id,name'])
            ->orderByRaw("CASE status WHEN 'draft' THEN 0 ELSE 1 END")
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate(30);

        return view('exco.decisions.index', [
            'decisions' => $decisions,
            'visibility' => app(DecisionReactionPresenter::class)->visibilityMode(),
            'windowDays' => (int) $this->settings->get(
                SettingsService::DECISION_REACTION_WINDOW_DAYS,
                Decision::DEFAULT_REACTION_WINDOW_DAYS,
            ) ?: Decision::DEFAULT_REACTION_WINDOW_DAYS,
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Decision::class);

        return view('exco.decisions.form', [
            'decision' => null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Decision::class);

        $data = $this->validated($request);
        $publish = $request->boolean('publish');

        $decision = DB::transaction(function () use ($data, $publish, $request): Decision {
            $decision = Decision::create([
                'title' => $data['title'],
                'body' => $data['body'],
                'reference' => $data['reference'] ?? null,
                'status' => $publish ? DecisionStatus::Published : DecisionStatus::Draft,
                'published_at' => $publish ? now() : null,
                'created_by' => $request->user()->id,
            ]);

            DecisionTally::create([
                'decision_id' => $decision->id,
                'likes' => 0,
                'dislikes' => 0,
                'neutrals' => 0,
            ]);

            return $decision;
        });

        if ($publish) {
            $this->publishNotifier->notifyPublished($decision);
        }

        return redirect()
            ->route('exco.decisions.index')
            ->with('success', $publish
                ? 'Decision published and emailed to active members.'
                : 'Decision saved as draft.');
    }

    public function edit(Decision $decision): View
    {
        $this->authorize('update', $decision);

        return view('exco.decisions.form', [
            'decision' => $decision,
        ]);
    }

    public function update(Request $request, Decision $decision): RedirectResponse
    {
        $this->authorize('update', $decision);

        $data = $this->validated($request);

        $decision->fill([
            'title' => $data['title'],
            'body' => $data['body'],
            'reference' => $data['reference'] ?? null,
        ]);

        // Publish only from draft. Never reset published_at on an already
        // published decision — that would restart the reaction window.
        $justPublished = false;
        if ($request->boolean('publish') && $decision->isDraft()) {
            $this->authorize('publish', $decision);
            $decision->status = DecisionStatus::Published;
            $decision->published_at = now();
            $justPublished = true;
        }

        $decision->save();

        if ($justPublished) {
            $this->publishNotifier->notifyPublished($decision);
        }

        return redirect()
            ->route('exco.decisions.index')
            ->with('success', $justPublished
                ? 'Decision published and emailed to active members.'
                : ($decision->isPublished() ? 'Decision updated.' : 'Draft updated.'));
    }

    public function publish(Decision $decision): RedirectResponse
    {
        $this->authorize('publish', $decision);

        // Gate::before auto-allows ExCo, so the policy's isDraft() check is
        // skipped for them — guard here so we never reset published_at or
        // re-broadcast a decision that already went out.
        if ($decision->isPublished()) {
            return redirect()
                ->route('exco.decisions.index')
                ->with('success', 'Decision is already published.');
        }

        $decision->status = DecisionStatus::Published;
        $decision->published_at = now();
        $decision->save();

        $this->publishNotifier->notifyPublished($decision);

        return redirect()
            ->route('exco.decisions.index')
            ->with('success', 'Decision published and emailed to active members.');
    }

    public function publishBatch(Request $request): RedirectResponse
    {
        $this->authorize('viewAny', Decision::class);

        $data = $request->validate([
            'decision_ids' => ['required', 'array', 'min:1'],
            'decision_ids.*' => ['integer', 'distinct', 'exists:decisions,id'],
        ]);

        $drafts = Decision::query()
            ->whereIn('id', $data['decision_ids'])
            ->where('status', DecisionStatus::Draft)
            ->whereNull('announcement_id')
            ->orderBy('id')
            ->get();

        if ($drafts->isEmpty()) {
            return redirect()
                ->route('exco.decisions.index')
                ->with('error', 'Select at least one draft decision to publish.');
        }

        foreach ($drafts as $draft) {
            $this->authorize('publish', $draft);
        }

        $this->publishNotifier->notifyPublishedBatch($drafts, publishDrafts: true);

        $count = $drafts->count();

        return redirect()
            ->route('exco.decisions.index')
            ->with('success', $count === 1
                ? 'Decision published and emailed to active members.'
                : $count.' decisions published in one email to active members.');
    }

    public function destroy(Decision $decision): RedirectResponse
    {
        $this->authorize('delete', $decision);

        $decision->delete();

        return redirect()
            ->route('exco.decisions.index')
            ->with('success', 'Draft deleted.');
    }

    public function updateVisibility(Request $request): RedirectResponse
    {
        $this->authorize('viewAny', Decision::class);

        $data = $request->validate([
            'decision_reaction_visibility' => [
                'required',
                Rule::in([
                    DecisionReactionPresenter::VISIBILITY_PUBLIC,
                    DecisionReactionPresenter::VISIBILITY_AFTER_REACTION,
                ]),
            ],
            'decision_reaction_window_days' => ['required', 'integer', 'min:1', 'max:90'],
        ]);

        $this->settings->set(
            SettingsService::DECISION_REACTION_VISIBILITY,
            $data['decision_reaction_visibility'],
            'When decision reaction totals are shown after the 10-reaction threshold',
        );

        $this->settings->set(
            SettingsService::DECISION_REACTION_WINDOW_DAYS,
            (string) $data['decision_reaction_window_days'],
            'Days after publish that members may react on a decision',
        );

        return redirect()
            ->route('exco.decisions.index')
            ->with('success', 'Reaction settings updated.');
    }

    /**
     * @return array{title: string, body: string, reference: ?string}
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:10000'],
            'reference' => ['nullable', 'string', 'max:200'],
        ]);
    }
}
