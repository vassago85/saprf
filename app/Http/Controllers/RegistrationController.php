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
                ->with(['match', 'user', 'division'])
                ->orderBy('registered_at')
                ->paginate(50)
                ->withQueryString();
        } elseif ($isPrivileged) {
            $registrations = MatchRegistration::query()->with(['match', 'user', 'division'])->latest()->paginate(20);
        } else {
            $registrations = $user->matchRegistrations()->with(['match', 'user', 'division'])->latest()->paginate(20);
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
        $this->authorize('view', $registration);

        $registration->load([
            'match',
            'user',
            'registeredBy',
            'division',
            'rifleConfiguration.make',
            'rifleConfiguration.model',
            'rifleConfiguration.calibre',
        ]);

        // Payer for the confirmed entry (if any). Ordered so the completed payment wins
        // over stale pending rows created when checkout was abandoned then retried.
        $payer = $registration->payments()
            ->with('user')
            ->orderByRaw("CASE WHEN status = 'completed' THEN 0 ELSE 1 END")
            ->latest('id')
            ->first()
            ?->user;

        $rifles = $request->user()->rifleConfigurations()
            ->active()
            ->with(['make', 'model', 'calibre'])
            ->orderByDesc('is_primary')
            ->get();

        $divisions = $registration->match?->availableDivisions() ?? collect();

        return view('registrations.show', compact('registration', 'rifles', 'divisions', 'payer'));
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

    /**
     * Change the division a shooter is entered under. Allowed for the shooter
     * (or staff) up until registration closes.
     */
    public function updateDivision(Request $request, MatchRegistration $registration): RedirectResponse
    {
        $isStaff = $request->user()->hasAnyRole(['owner', 'admin', 'match_director']);

        if ($registration->user_id !== $request->user()->id && ! $isStaff) {
            abort(403);
        }

        // Members can only change their entry while registration is open; staff
        // may adjust it at any time.
        if (! $isStaff && ! $registration->canEditEntry()) {
            return back()->with('error', 'Registration has closed — the division can no longer be changed.');
        }

        $allowedDivisionIds = $registration->match?->availableDivisions()->pluck('id')->all() ?? [];

        $validated = $request->validate([
            'division_id' => ['required', \Illuminate\Validation\Rule::in($allowedDivisionIds)],
        ], [
            'division_id.required' => 'Please choose a division.',
            'division_id.in' => 'The selected division is not available for this match.',
        ]);

        $old = $registration->only(['division_id']);
        $registration->update($validated);

        $this->auditLogService->log(
            $request->user(),
            'registration.division.updated',
            'MatchRegistration',
            $registration->id,
            $old,
            $validated,
        );

        return redirect()->route('registrations.show', $registration)
            ->with('success', 'Division updated.');
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

        $message = match ($refundCalc['reason']) {
            'free_entry'      => 'Registration withdrawn. This was a free entry — no financial impact.',
            'unpaid'          => 'Registration withdrawn. No payment was collected, so no refund is due.',
            'past_deadline'   => 'Registration withdrawn. No refund — withdrawal was after the deadline.',
            'before_deadline' => $refundCalc['refund'] > 0
                ? 'Registration withdrawn. Refund of R ' . number_format($refundCalc['refund'], 2) . ' (minus R ' . number_format($refundCalc['admin_fee'], 2) . ' admin fee).'
                : 'Registration withdrawn. The admin fee equalled or exceeded the entry fee, so no refund is due.',
            default           => 'Registration withdrawn.',
        };

        return redirect()->route('registrations.show', $registration)
            ->with('success', $message);
    }
}
