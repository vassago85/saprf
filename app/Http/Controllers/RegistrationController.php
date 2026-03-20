<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRegistrationRequest;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Services\AuditLogService;
use App\Services\RegistrationPricingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RegistrationController extends Controller
{
    public function __construct(
        private readonly RegistrationPricingService $pricingService,
        private readonly AuditLogService $auditLogService,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        $registrations = $user->hasAnyRole(['owner', 'admin', 'match_director'])
            ? MatchRegistration::query()->with(['match', 'user'])->latest()->paginate(20)
            : $user->matchRegistrations()->with('match')->latest()->paginate(20);

        return view('registrations.index', compact('registrations'));
    }

    public function store(StoreRegistrationRequest $request): RedirectResponse
    {
        $user = $request->user();
        $match = MatchEvent::query()->findOrFail($request->validated('match_id'));

        $pricing = $this->pricingService->determineCategoryAndFee($match, $user, $match->match_date);

        $registration = MatchRegistration::query()->create([
            'match_id' => $match->id,
            'user_id' => $user->id,
            'shooter_name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'membership_fee_category' => $pricing['category'],
            'fee_amount' => $pricing['fee'],
            'payment_status' => 'unpaid',
            'registration_status' => 'pending',
            'registered_at' => now(),
        ]);

        $this->auditLogService->log(
            $user,
            'registration.created',
            'MatchRegistration',
            $registration->id,
            null,
            $registration->toArray(),
        );

        return redirect()->route('registrations.show', $registration)
            ->with('success', 'Registration submitted successfully.');
    }

    public function show(MatchRegistration $registration): View
    {
        $registration->load(['match', 'user']);

        return view('registrations.show', compact('registration'));
    }

    public function updateStatus(Request $request, MatchRegistration $registration): RedirectResponse
    {
        $this->authorize('update', $registration);

        $validated = $request->validate([
            'registration_status' => ['required', 'in:confirmed,cancelled'],
        ]);

        $old = $registration->only(['registration_status']);
        $registration->update($validated);

        $this->auditLogService->log(
            $request->user(),
            'registration.status.updated',
            'MatchRegistration',
            $registration->id,
            $old,
            $registration->only(['registration_status']),
        );

        return redirect()->route('registrations.show', $registration)
            ->with('success', 'Registration status updated.');
    }
}
