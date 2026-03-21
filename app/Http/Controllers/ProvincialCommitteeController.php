<?php

namespace App\Http\Controllers;

use App\Models\Province;
use App\Models\ProvincialCommittee;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProvincialCommitteeController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    public function index(): View
    {
        $provinces = Province::with(['committeeMembers' => fn ($q) => $q->active()->with('user')])
            ->orderBy('name')
            ->get();

        return view('provincial-committees.index', compact('provinces'));
    }

    public function create(): View
    {
        $provinces = Province::orderBy('name')->get();
        $users = User::orderBy('name')->get(['id', 'name', 'email']);
        $positions = ProvincialCommittee::POSITIONS;

        return view('provincial-committees.create', compact('provinces', 'users', 'positions'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'province_id' => ['required', 'exists:provinces,id'],
            'user_id' => ['required', 'exists:users,id'],
            'position' => ['required', 'string', 'in:' . implode(',', ProvincialCommittee::POSITIONS)],
            'appointed_at' => ['nullable', 'date'],
        ]);

        $existing = ProvincialCommittee::where('province_id', $validated['province_id'])
            ->where('user_id', $validated['user_id'])
            ->first();

        if ($existing) {
            return back()->with('error', 'This user is already on this province\'s committee.');
        }

        $appointment = ProvincialCommittee::create([
            ...$validated,
            'is_active' => true,
            'appointed_at' => $validated['appointed_at'] ?? now(),
        ]);

        $user = User::find($validated['user_id']);
        if (! $user->hasRole('provincial_admin')) {
            $user->assignRole('provincial_admin');
        }

        $this->auditLogService->log(
            $request->user(),
            'committee_member_appointed',
            'ProvincialCommittee',
            $appointment->id,
            null,
            $validated,
            "Appointed {$user->name} as {$appointment->positionLabel()} for " . $appointment->province->name,
        );

        return redirect()->route('provincial-committees.index')
            ->with('success', "{$user->name} appointed to {$appointment->province->name} committee.");
    }

    public function show(Province $provincialCommittee): View
    {
        $province = $provincialCommittee;
        $members = ProvincialCommittee::where('province_id', $province->id)
            ->with('user')
            ->orderByRaw("FIELD(position, 'chair', 'vice_chair', 'treasurer', 'secretary', 'member')")
            ->get();

        return view('provincial-committees.show', compact('province', 'members'));
    }

    public function edit(ProvincialCommittee $provincialCommittee): View
    {
        $provincialCommittee->load(['province', 'user']);
        $positions = ProvincialCommittee::POSITIONS;

        return view('provincial-committees.edit', [
            'appointment' => $provincialCommittee,
            'positions' => $positions,
        ]);
    }

    public function update(Request $request, ProvincialCommittee $provincialCommittee): RedirectResponse
    {
        $validated = $request->validate([
            'position' => ['required', 'string', 'in:' . implode(',', ProvincialCommittee::POSITIONS)],
            'is_active' => ['sometimes', 'boolean'],
            'appointed_at' => ['nullable', 'date'],
        ]);

        $old = $provincialCommittee->only(['position', 'is_active']);
        $validated['is_active'] = $request->boolean('is_active', true);

        $provincialCommittee->update($validated);

        $this->auditLogService->log(
            $request->user(),
            'committee_member_updated',
            'ProvincialCommittee',
            $provincialCommittee->id,
            $old,
            $validated,
            "Updated {$provincialCommittee->user->name}'s committee position",
        );

        return redirect()->route('provincial-committees.index')
            ->with('success', 'Committee appointment updated.');
    }
}
