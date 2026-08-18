<?php

namespace App\Http\Controllers;

use App\Enums\AudienceMode;
use App\Enums\AudienceType;
use App\Models\Club;
use App\Models\Division;
use App\Models\MembershipFeeTier;
use App\Models\Province;
use App\Models\SavedDistributionList;
use App\Services\Announcements\AudienceResolver;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * CRUD for reusable audience rule sets ("Send to all match directors",
 * "Provincial admins in Gauteng", …). Once saved, an Exco composing an
 * announcement can pick the list by name and the resolver expands the
 * embedded rules at send time.
 *
 * Route-gated to `developer|exco|chair`. The composer preview is public
 * within the group; nothing here is member-facing.
 *
 * Cycle guard: a `saved_list` rule may only reference a *different*
 * saved list. We could detect a full cycle by recursing, but the
 * simplest correct rule — and the one that actually matters in practice
 * — is "you cannot reference yourself".
 */
class SavedDistributionListController extends Controller
{
    public function __construct(
        private readonly AudienceResolver $resolver,
        private readonly AuditLogService $auditLogService,
    ) {}

    public function index(): View
    {
        $lists = SavedDistributionList::query()
            ->with('creator:id,name')
            ->orderBy('name')
            ->paginate(20);

        return view('saved-lists.index', [
            'lists' => $lists,
        ]);
    }

    public function create(): View
    {
        return view('saved-lists.form', array_merge(
            ['list' => null],
            $this->composerContext(),
        ));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        try {
            $this->assertNoSelfReference($data['rules'] ?? [], selfId: null);
        } catch (RuntimeException $e) {
            return back()->withErrors(['rules' => $e->getMessage()])->withInput();
        }

        $list = SavedDistributionList::create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'rules' => $data['rules'] ?? [],
            'created_by' => $request->user()->id,
        ]);

        $this->auditLogService->log(
            $request->user(),
            'saved_list.created',
            'SavedDistributionList',
            $list->id,
            null,
            ['name' => $list->name, 'rule_count' => count($data['rules'] ?? [])],
        );

        return redirect()->route('saved-lists.index')
            ->with('success', "Saved distribution list '{$list->name}' created.");
    }

    public function edit(SavedDistributionList $savedList): View
    {
        return view('saved-lists.form', array_merge(
            ['list' => $savedList],
            $this->composerContext(),
        ));
    }

    public function update(Request $request, SavedDistributionList $savedList): RedirectResponse
    {
        $data = $this->validated($request);

        try {
            $this->assertNoSelfReference($data['rules'] ?? [], selfId: $savedList->id);
        } catch (RuntimeException $e) {
            return back()->withErrors(['rules' => $e->getMessage()])->withInput();
        }

        $original = ['name' => $savedList->name, 'rules' => $savedList->rules];

        $savedList->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'rules' => $data['rules'] ?? [],
        ]);

        $this->auditLogService->log(
            $request->user(),
            'saved_list.updated',
            'SavedDistributionList',
            $savedList->id,
            $original,
            ['name' => $savedList->name, 'rule_count' => count($data['rules'] ?? [])],
        );

        return redirect()->route('saved-lists.index')
            ->with('success', "Saved distribution list '{$savedList->name}' updated.");
    }

    public function destroy(Request $request, SavedDistributionList $savedList): RedirectResponse
    {
        $savedList->delete();

        $this->auditLogService->log(
            $request->user(),
            'saved_list.deleted',
            'SavedDistributionList',
            $savedList->id,
            ['name' => $savedList->name],
            null,
        );

        return redirect()->route('saved-lists.index')
            ->with('success', "Deleted saved list '{$savedList->name}'.");
    }

    /**
     * Live preview endpoint for the saved-list form. Uses the same
     * resolver as the announcement composer so what you see here is
     * what you get at send time.
     */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'rules' => ['array'],
            'rules.*.type' => ['required', 'string', 'in:' . implode(',', array_column(AudienceType::cases(), 'value'))],
            'rules.*.mode' => ['required', 'string', 'in:include,exclude'],
            'rules.*.value' => ['nullable', 'array'],
        ]);

        $rules = collect($data['rules'] ?? [])->map(fn (array $rule) => [
            'type' => AudienceType::from($rule['type']),
            'mode' => AudienceMode::from($rule['mode']),
            'value' => $rule['value'] ?? [],
        ]);

        $preview = $this->resolver->preview($rules, sample: 10);

        return response()->json([
            'count' => $preview->count,
            'sample' => $preview->sample->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
            ])->values(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function composerContext(): array
    {
        return [
            'audienceTypes' => AudienceType::cases(),
            'divisions' => Division::query()->orderBy('display_order')->orderBy('name')->get(['id', 'name']),
            'provinces' => Province::query()->orderBy('name')->get(['id', 'name']),
            'clubs' => Club::query()->orderBy('name')->get(['id', 'name']),
            'feeTiers' => MembershipFeeTier::query()->orderBy('display_order')->orderBy('name')->get(['id', 'name']),
            'roles' => ['exco', 'chair', 'admin', 'match_director', 'iprf_selector', 'provincial_admin', 'member'],
            'savedLists' => SavedDistributionList::query()->orderBy('name')->get(['id', 'name']),
        ];
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'rules' => ['required', 'array', 'min:1'],
            'rules.*.type' => ['required', 'string', 'in:' . implode(',', array_column(AudienceType::cases(), 'value'))],
            'rules.*.mode' => ['required', 'string', 'in:include,exclude'],
            'rules.*.value' => ['nullable', 'array'],
        ]);
    }

    /**
     * Cycle guard for saved-list references. In practice a chain of
     * references is unlikely, but stopping the trivial case "this list
     * references itself" is cheap and catches the copy-paste bug where
     * someone edits a list and re-picks its own name as a saved_list rule.
     *
     * @param  array<int, array{type: string, value?: array}>  $rules
     */
    private function assertNoSelfReference(array $rules, ?int $selfId): void
    {
        if ($selfId === null) {
            return;
        }

        foreach ($rules as $rule) {
            if (($rule['type'] ?? null) !== AudienceType::SavedList->value) {
                continue;
            }
            $referenced = $rule['value']['list_id'] ?? null;
            if ((int) $referenced === $selfId) {
                throw new RuntimeException('A saved list may not reference itself.');
            }
        }
    }
}
