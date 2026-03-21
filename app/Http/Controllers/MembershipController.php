<?php

namespace App\Http\Controllers;

use App\Models\Membership;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MembershipController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    public function myMembership(Request $request): View
    {
        $user = $request->user();
        $membership = Membership::where('user_id', $user->id)->latest()->first();
        $fee = (float) app(SettingsService::class)->get('annual_membership_fee', 500);
        $paymentsEnabled = (bool) app(SettingsService::class)->get('payments_enabled', false);

        return view('memberships.my-membership', compact('membership', 'user', 'fee', 'paymentsEnabled'));
    }

    public function index(Request $request): View
    {
        $user = $request->user();

        $memberships = $user->hasAnyRole(['owner', 'admin'])
            ? Membership::query()->with('user')->latest()->paginate(20)
            : Membership::query()->where('user_id', $user->id)->paginate(20);

        return view('memberships.index', compact('memberships'));
    }

    public function show(Membership $membership): View
    {
        $this->authorize('view', $membership);

        $membership->load(['user', 'payments']);

        return view('memberships.show', compact('membership'));
    }

    public function create(): View
    {
        $users = User::orderBy('name')->get(['id', 'name', 'email']);

        return view('memberships.create', compact('users'));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Membership::class);

        $validated = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'membership_type' => ['required', 'string', 'max:50'],
            'status' => ['required', 'in:active,pending,lapsed,expired'],
            'payment_status' => ['required', 'in:paid,unpaid,partial'],
            'start_date' => ['required', 'date'],
            'expiry_date' => ['required', 'date', 'after:start_date'],
        ]);

        $membership = Membership::query()->create($validated);

        $this->auditLogService->log(
            $request->user(),
            'membership.created',
            'Membership',
            $membership->id,
            null,
            $membership->toArray(),
        );

        return redirect()->route('memberships.show', $membership)
            ->with('success', 'Membership created successfully.');
    }

    public function edit(Membership $membership): View
    {
        $this->authorize('update', $membership);

        $membership->load('user');

        return view('memberships.edit', compact('membership'));
    }

    public function update(Request $request, Membership $membership): RedirectResponse
    {
        $this->authorize('update', $membership);

        $validated = $request->validate([
            'status' => ['required', 'in:active,pending,lapsed,expired'],
            'payment_status' => ['required', 'in:paid,unpaid,partial'],
            'expiry_date' => ['nullable', 'date'],
        ]);

        $old = $membership->only(['status', 'payment_status', 'expiry_date']);
        $membership->update($validated);

        $this->auditLogService->log(
            $request->user(),
            'membership.updated',
            'Membership',
            $membership->id,
            $old,
            $membership->only(['status', 'payment_status', 'expiry_date']),
        );

        return redirect()->route('memberships.show', $membership)
            ->with('success', 'Membership updated successfully.');
    }
}
