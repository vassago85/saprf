<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRegistrationRequest;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Notifications\MatchRegistrationConfirmedNotification;
use App\Services\AuditLogService;
use App\Services\RegistrationPricingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
        $isPrivileged = $user->hasAnyRole(['developer', 'exco', 'owner', 'admin', 'match_director']);

        $matchId = $request->integer('match_id') ?: null;
        $match = $matchId ? MatchEvent::find($matchId) : null;

        if ($match) {
            // Public entry list for a single match — everyone can see who has
            // registered. Cancelled/withdrawn entries are hidden.
            $registrations = $match->registrations()
                ->where('registration_status', '!=', 'cancelled')
                ->with(['match', 'user'])
                ->orderBy('registered_at')
                ->paginate(50)
                ->withQueryString();
        } elseif ($isPrivileged) {
            $registrations = MatchRegistration::query()->with(['match', 'user'])->latest()->paginate(20);
        } else {
            $registrations = $user->matchRegistrations()->with(['match', 'user'])->latest()->paginate(20);
        }

        // Fee/payment columns stay restricted to organisers; the entry list
        // itself (names + category + status) is visible to everyone.
        $canViewFinancials = $isPrivileged;

        return view('registrations.index', compact('registrations', 'match', 'canViewFinancials', 'isPrivileged'));
    }

    public function store(StoreRegistrationRequest $request): RedirectResponse
    {
        $user = $request->user();
        $match = MatchEvent::query()->findOrFail($request->validated('match_id'));

        $breakdown = $this->pricingService->calculateBreakdown($match, $user, $match->match_date);

        $registration = MatchRegistration::query()->create([
            'match_id' => $match->id,
            'user_id' => $user->id,
            'shooter_name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'membership_fee_category' => $breakdown['category'],
            'fee_amount' => $breakdown['total_fee'],
            'surcharge_amount' => $breakdown['surcharge'],
            'saprf_fee' => $breakdown['saprf_fee'],
            'platform_fee' => $breakdown['platform_fee'],
            'gateway_fee' => $breakdown['gateway_fee'],
            'md_net_amount' => $breakdown['md_net'],
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

        try {
            $user->notify(new MatchRegistrationConfirmedNotification($registration));
        } catch (\Throwable $e) {
            Log::warning('Failed to send match registration notification', ['error' => $e->getMessage()]);
        }

        return redirect()->route('registrations.show', $registration)
            ->with('success', 'Registration submitted successfully.');
    }

    public function show(Request $request, MatchRegistration $registration): View
    {
        $registration->load(['match', 'user', 'rifleConfiguration.make', 'rifleConfiguration.model', 'rifleConfiguration.calibre']);

        $rifles = $request->user()->rifleConfigurations()
            ->active()
            ->with(['make', 'model', 'calibre'])
            ->orderByDesc('is_primary')
            ->get();

        return view('registrations.show', compact('registration', 'rifles'));
    }

    public function updateRifle(Request $request, MatchRegistration $registration): RedirectResponse
    {
        if ($registration->user_id !== $request->user()->id && ! $request->user()->hasAnyRole(['owner', 'admin', 'match_director'])) {
            abort(403);
        }

        $validated = $request->validate([
            'rifle_configuration_id' => ['nullable', 'exists:rifle_configurations,id'],
        ]);

        $old = $registration->only(['rifle_configuration_id']);
        $registration->update($validated);

        $this->auditLogService->log(
            $request->user(),
            'registration.rifle.updated',
            'MatchRegistration',
            $registration->id,
            $old,
            $validated,
        );

        return redirect()->route('registrations.show', $registration)
            ->with('success', 'Rifle configuration updated.');
    }

    public function updateShotCount(Request $request, MatchRegistration $registration): RedirectResponse
    {
        if ($registration->user_id !== $request->user()->id && ! $request->user()->hasAnyRole(['owner', 'admin', 'match_director'])) {
            abort(403);
        }

        $validated = $request->validate([
            'shot_count' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        $old = $registration->only(['shot_count']);
        $registration->update($validated);

        if ($registration->rifle_configuration_id) {
            $registration->rifleConfiguration->recalculateShotCount();
        }

        $this->auditLogService->log(
            $request->user(),
            'registration.shots.updated',
            'MatchRegistration',
            $registration->id,
            $old,
            $validated,
        );

        return redirect()->route('registrations.show', $registration)
            ->with('success', 'Shot count updated.');
    }

    public function updateStatus(Request $request, MatchRegistration $registration): RedirectResponse
    {
        $this->authorize('update', $registration);

        $validated = $request->validate([
            'registration_status' => ['required', 'in:confirmed,cancelled,pending,waitlisted'],
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

    public function withdraw(Request $request, MatchRegistration $registration): RedirectResponse
    {
        $user = $request->user();

        if ($registration->user_id !== $user->id) {
            abort(403, 'You can only withdraw your own registration.');
        }

        if (! $registration->isWithdrawable()) {
            return back()->with('error', 'This registration cannot be withdrawn.');
        }

        $request->validate([
            'cancellation_reason' => ['nullable', 'string', 'max:500'],
        ]);

        $refundCalc = $registration->calculateRefund();
        $old = $registration->only(['registration_status', 'payment_status']);

        $registration->update([
            'registration_status' => 'cancelled',
            'cancelled_at' => now(),
            'cancellation_reason' => $request->input('cancellation_reason'),
            'refund_amount' => $refundCalc['refund'],
            'admin_fee_charged' => $refundCalc['admin_fee'],
        ]);

        $this->auditLogService->log(
            $user,
            'registration.withdrawn',
            'MatchRegistration',
            $registration->id,
            $old,
            [
                'registration_status' => 'cancelled',
                'refund_amount' => $refundCalc['refund'],
                'admin_fee_charged' => $refundCalc['admin_fee'],
                'reason' => $refundCalc['reason'],
            ],
        );

        $message = $refundCalc['refund'] > 0
            ? 'Registration withdrawn. Refund of R ' . number_format($refundCalc['refund'], 2) . ' (minus R ' . number_format($refundCalc['admin_fee'], 2) . ' admin fee).'
            : 'Registration withdrawn. No refund — withdrawal was after the deadline.';

        return redirect()->route('registrations.show', $registration)
            ->with('success', $message);
    }
}
