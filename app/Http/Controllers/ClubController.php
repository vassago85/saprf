<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Models\Province;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin CRUD for shooting clubs. Supports the SAPRF-recognition toggle
 * (used by the IPRF selection engine's ELG-03 / ELG-05 checks) and a
 * "merge into another club" flow that reassigns every user from a source
 * club to a target and deletes the source — the primary tool for cleaning
 * up duplicate club entries that come out of the CSV importer.
 */
class ClubController extends Controller
{
    public function __construct(private readonly AuditLogService $auditLogService) {}

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Club::class);

        $search = trim((string) $request->query('q', ''));
        $provinceId = $request->query('province_id');
        $recognition = $request->query('recognition'); // '', 'recognised', 'unrecognised'
        $active = $request->query('active'); // '', 'active', 'inactive'

        $clubs = Club::query()
            ->with('province:id,name')
            ->withCount('users')
            ->when($search !== '', fn ($q) => $q->where(function ($qq) use ($search) {
                $qq->where('name', 'like', "%{$search}%")
                    ->orWhere('abbreviation', 'like', "%{$search}%");
            }))
            ->when($provinceId, fn ($q) => $q->where('province_id', $provinceId))
            ->when($recognition === 'recognised', fn ($q) => $q->where('saprf_recognised', true))
            ->when($recognition === 'unrecognised', fn ($q) => $q->where('saprf_recognised', false))
            ->when($active === 'active', fn ($q) => $q->where('is_active', true))
            ->when($active === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderBy('name')
            ->paginate(30)
            ->withQueryString();

        return view('clubs.index', [
            'clubs' => $clubs,
            'provinces' => Province::orderBy('name')->get(['id', 'name']),
            'filters' => compact('search', 'provinceId', 'recognition', 'active'),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', Club::class);

        return view('clubs.create', [
            'provinces' => Province::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Club::class);
        $data = $this->validated($request);
        $data['slug'] = $this->uniqueSlug($data['name']);

        $club = Club::create($data);

        $this->auditLogService->log(
            $request->user(),
            'club_created',
            'Club',
            $club->id,
            null,
            $club->only(['name', 'slug', 'province_id', 'saprf_recognised', 'is_active']),
            "Club {$club->name} created",
        );

        return redirect()->route('clubs.index')
            ->with('success', "Club '{$club->name}' created.");
    }

    public function edit(Club $club): View
    {
        Gate::authorize('update', $club);

        return view('clubs.edit', [
            'club' => $club->loadCount('users'),
            'provinces' => Province::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, Club $club): RedirectResponse
    {
        Gate::authorize('update', $club);
        $data = $this->validated($request, $club->id);
        if ($data['name'] !== $club->name) {
            $data['slug'] = $this->uniqueSlug($data['name'], $club->id);
        }

        $old = $club->only(['name', 'slug', 'abbreviation', 'province_id', 'saprf_recognised', 'is_active']);
        $club->update($data);

        $this->auditLogService->log(
            $request->user(),
            'club_updated',
            'Club',
            $club->id,
            $old,
            $club->only(['name', 'slug', 'abbreviation', 'province_id', 'saprf_recognised', 'is_active']),
            "Club {$club->name} updated",
        );

        return redirect()->route('clubs.index')
            ->with('success', "Club '{$club->name}' updated.");
    }

    /**
     * One-click SAPRF-recognition toggle, used from the index row-actions
     * column. Kept separate from update() so it doesn't require re-posting
     * the whole edit form and can be permission-gated the same way as
     * update.
     */
    public function toggleRecognition(Request $request, Club $club): RedirectResponse
    {
        Gate::authorize('update', $club);

        $old = ['saprf_recognised' => $club->saprf_recognised];
        $club->saprf_recognised = ! $club->saprf_recognised;
        $club->save();

        $this->auditLogService->log(
            $request->user(),
            'club_recognition_toggled',
            'Club',
            $club->id,
            $old,
            ['saprf_recognised' => $club->saprf_recognised],
            "Club {$club->name} recognition set to " . ($club->saprf_recognised ? 'recognised' : 'unrecognised'),
        );

        return back()->with('success', "'{$club->name}' is now " . ($club->saprf_recognised ? 'SAPRF-recognised' : 'not SAPRF-recognised') . '.');
    }

    public function mergeForm(Club $club): View
    {
        Gate::authorize('merge', $club);

        $targets = Club::query()
            ->where('id', '!=', $club->id)
            ->with('province:id,name')
            ->withCount('users')
            ->orderBy('name')
            ->get();

        return view('clubs.merge', [
            'source' => $club->loadCount('users'),
            'targets' => $targets,
        ]);
    }

    /**
     * Reassign every user on the source `$club` to the target and delete
     * the source. All-or-nothing transaction — if anything fails, no
     * users get moved and the source club stays intact.
     *
     * NOTE: The route param is `{club}`, so this method's parameter MUST
     * be named `$club` — Laravel route model binding matches by name, and
     * a mismatched name silently injects an empty Club instance.
     */
    public function merge(Request $request, Club $club): RedirectResponse
    {
        Gate::authorize('merge', $club);

        $data = $request->validate([
            'target_id' => [
                'required',
                'integer',
                'not_in:'.$club->id,
                Rule::exists('clubs', 'id'),
            ],
        ], [
            'target_id.not_in' => 'You cannot merge a club into itself.',
        ]);
        $target = Club::findOrFail($data['target_id']);
        $sourceName = $club->name;
        $sourceUserCount = $club->loadCount('users')->users_count;

        DB::transaction(function () use ($club, $target) {
            User::where('club_id', $club->id)->update(['club_id' => $target->id]);
            $club->delete();
        });

        $this->auditLogService->log(
            $request->user(),
            'club_merged',
            'Club',
            $target->id,
            [
                'source_id' => $club->id,
                'source_name' => $sourceName,
                'source_users_before' => $sourceUserCount,
            ],
            [
                'target_id' => $target->id,
                'target_name' => $target->name,
            ],
            "Merged '{$sourceName}' into '{$target->name}'",
        );

        return redirect()->route('clubs.index')
            ->with('success', "Merged '{$sourceName}' into '{$target->name}'.");
    }

    public function destroy(Request $request, Club $club): RedirectResponse
    {
        Gate::authorize('delete', $club);

        if ($club->users()->exists()) {
            return back()->with('error', "'{$club->name}' still has members — reassign or merge them first.");
        }

        $snapshot = $club->only(['id', 'name', 'slug', 'province_id']);
        $club->delete();

        $this->auditLogService->log(
            $request->user(),
            'club_deleted',
            'Club',
            $snapshot['id'],
            $snapshot,
            null,
            "Club {$snapshot['name']} deleted",
        );

        return redirect()->route('clubs.index')
            ->with('success', "Club '{$snapshot['name']}' deleted.");
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        $nameRule = Rule::unique('clubs', 'name');
        if ($ignoreId) {
            $nameRule = $nameRule->ignore($ignoreId);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', $nameRule],
            'abbreviation' => ['nullable', 'string', 'max:20'],
            'province_id' => ['nullable', 'exists:provinces,id'],
            'saprf_recognised' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        // HTML checkboxes only post when ticked, so we can't rely on the
        // validated array to represent an unticked box as false — override
        // both explicitly from the raw request.
        $validated['saprf_recognised'] = (bool) $request->input('saprf_recognised', false);
        $validated['is_active'] = (bool) $request->input('is_active', false);

        return $validated;
    }

    private function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'club';
        $slug = $base;
        $i = 2;
        while (Club::query()
            ->where('slug', $slug)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists()
        ) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
