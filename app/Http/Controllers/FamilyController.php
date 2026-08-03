<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreJuniorRequest;
use App\Models\Division;
use App\Models\Province;
use App\Models\User;
use App\Notifications\AccountHandoverInvitationNotification;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;

class FamilyController extends Controller
{
    private const HANDOVER_TTL_DAYS = 14;

    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    // ──────────────────────────────────────────────────────────────────────
    // Parent-facing routes
    // ──────────────────────────────────────────────────────────────────────

    public function index(Request $request): View
    {
        $juniors = $request->user()
            ->managedAccounts()
            ->with(['province', 'division', 'membership'])
            ->orderBy('name')
            ->get();

        return view('family.index', compact('juniors'));
    }

    public function create(): View
    {
        $provinces = Province::orderBy('name')->get();
        $divisions = Division::active()->ordered()->get();

        return view('family.create', compact('provinces', 'divisions'));
    }

    public function store(StoreJuniorRequest $request): RedirectResponse
    {
        $parent = $request->user();
        $data = $request->validated();

        $junior = DB::transaction(function () use ($parent, $data) {
            // Auto-generate placeholder email — managed accounts don't log in directly.
            // Format: managed-{slug}-{rand}@managed.saprf.co.za
            $slug = Str::slug($data['name']);
            $placeholderEmail = sprintf(
                'managed-%s-%s@managed.saprf.co.za',
                $slug ?: 'shooter',
                Str::lower(Str::random(6)),
            );

            $junior = User::create([
                'parent_id' => $parent->id,
                'is_managed_account' => true,
                'managed_relationship' => $data['relationship'],
                'name' => $data['name'],
                'email' => $placeholderEmail,
                'password' => Hash::make(Str::random(40)), // unusable password
                'date_of_birth' => $data['date_of_birth'] ?? null,
                'province_id' => $data['province_id'],
                'division_id' => $data['division_id'] ?? null,
                'email_verified_at' => now(), // managed accounts don't need OTP verification
            ]);

            $junior->assignRole('member');

            return $junior;
        });

        $this->auditLogService->log(
            $parent,
            'family.member.created',
            'User',
            $junior->id,
            null,
            ['name' => $junior->name, 'relationship' => $junior->managed_relationship, 'parent_id' => $parent->id],
        );

        return redirect()->route('family.index')
            ->with('success', $junior->name . ' has been added to your family. You can now register them for matches and pay from your account.');
    }

    public function show(Request $request, User $junior): View
    {
        $this->authorizeJunior($request, $junior);

        $junior->load(['province', 'division', 'membership']);

        $upcomingRegistrations = $junior->matchRegistrations()
            ->with('match')
            ->whereHas('match', fn ($q) => $q->whereDate('match_date', '>=', now()->toDateString()))
            ->orderBy('registered_at', 'desc')
            ->get();

        $recentScores = $junior->scores()
            ->with('match')
            ->where('status', 'valid')
            ->latest('match_date')
            ->limit(5)
            ->get();

        return view('family.show', compact('junior', 'upcomingRegistrations', 'recentScores'));
    }

    public function edit(Request $request, User $junior): View
    {
        $this->authorizeJunior($request, $junior);

        $provinces = Province::orderBy('name')->get();
        $divisions = Division::active()->ordered()->get();

        return view('family.edit', compact('junior', 'provinces', 'divisions'));
    }

    public function update(Request $request, User $junior): RedirectResponse
    {
        $this->authorizeJunior($request, $junior);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'relationship' => ['required', \Illuminate\Validation\Rule::in(array_keys(User::MANAGED_RELATIONSHIPS))],
            'date_of_birth' => [
                \Illuminate\Validation\Rule::requiredIf(fn () => $request->input('relationship') === 'junior'),
                'nullable', 'date', 'before:today',
            ],
            'province_id' => ['required', 'exists:provinces,id'],
            'division_id' => ['required', 'exists:divisions,id'],
        ], [
            'date_of_birth.required' => 'Date of birth is required for junior accounts.',
        ]);

        $old = $junior->only(['name', 'managed_relationship', 'date_of_birth', 'province_id', 'division_id']);

        $junior->update([
            'name' => $data['name'],
            'managed_relationship' => $data['relationship'],
            'date_of_birth' => $data['date_of_birth'] ?? null,
            'province_id' => $data['province_id'],
            'division_id' => $data['division_id'] ?? null,
        ]);

        $this->auditLogService->log(
            $request->user(),
            'family.member.updated',
            'User',
            $junior->id,
            $old,
            $junior->only(['name', 'managed_relationship', 'date_of_birth', 'province_id', 'division_id']),
        );

        return redirect()->route('family.show', $junior)
            ->with('success', $junior->name . ' updated.');
    }

    // ──────────────────────────────────────────────────────────────────────
    // Handover flow (parent initiates)
    // ──────────────────────────────────────────────────────────────────────

    public function startHandover(Request $request, User $junior): RedirectResponse
    {
        $this->authorizeJunior($request, $junior);

        $data = $request->validate([
            'handover_email' => ['required', 'email', 'max:120', 'unique:users,email'],
        ], [
            'handover_email.unique' => 'A user with this email already exists. They must use a fresh email address.',
        ]);

        $plainToken = Str::random(60);
        $hashedToken = hash('sha256', $plainToken);

        $junior->update([
            'handover_email' => $data['handover_email'],
            'handover_token' => $hashedToken,
            'handover_expires_at' => now()->addDays(self::HANDOVER_TTL_DAYS),
        ]);

        try {
            $junior->notify(new AccountHandoverInvitationNotification(
                $junior,
                $request->user(),
                $plainToken,
            ));
        } catch (\Throwable $e) {
            Log::warning('Failed to send handover invitation', ['error' => $e->getMessage()]);
        }

        $this->auditLogService->log(
            $request->user(),
            'family.member.handover.started',
            'User',
            $junior->id,
            null,
            ['handover_email' => $data['handover_email']],
        );

        return redirect()->route('family.show', $junior)
            ->with('success', 'Handover invitation sent to ' . $data['handover_email'] . '. The link expires in ' . self::HANDOVER_TTL_DAYS . ' days.');
    }

    public function destroy(Request $request, User $junior): RedirectResponse
    {
        $this->authorizeJunior($request, $junior);

        // Guard: refuse to remove a managed account with historical data —
        // scores and confirmed registrations must not disappear silently.
        // The parent can start a handover instead to migrate the account.
        $scoreCount = $junior->scores()->count();
        $activeRegistrations = $junior->matchRegistrations()
            ->whereIn('registration_status', ['pending', 'confirmed', 'waitlisted'])
            ->count();

        if ($scoreCount > 0) {
            return redirect()->route('family.show', $junior)
                ->with('error', "{$junior->name} has {$scoreCount} recorded score(s) and cannot be removed. Their competition history must be preserved. Use \"Hand over account\" if they should manage their own account instead.");
        }

        if ($activeRegistrations > 0) {
            return redirect()->route('family.show', $junior)
                ->with('error', "{$junior->name} has {$activeRegistrations} active registration(s). Withdraw those first, then try again.");
        }

        $juniorName = $junior->name;
        $juniorId = $junior->id;

        DB::transaction(function () use ($junior) {
            // Clear any lingering handover token before soft-deleting.
            if ($junior->handover_token) {
                $junior->update([
                    'handover_email' => null,
                    'handover_token' => null,
                    'handover_expires_at' => null,
                ]);
            }

            $junior->delete(); // Soft delete — the User model uses SoftDeletes.
        });

        $this->auditLogService->log(
            $request->user(),
            'family.member.removed',
            'User',
            $juniorId,
            ['name' => $juniorName, 'parent_id' => $request->user()->id],
            null,
        );

        return redirect()->route('family.index')
            ->with('success', "{$juniorName} has been removed from your family.");
    }

    public function cancelHandover(Request $request, User $junior): RedirectResponse
    {
        $this->authorizeJunior($request, $junior);

        $old = $junior->only(['handover_email']);

        $junior->update([
            'handover_email' => null,
            'handover_token' => null,
            'handover_expires_at' => null,
        ]);

        $this->auditLogService->log(
            $request->user(),
            'family.member.handover.cancelled',
            'User',
            $junior->id,
            $old,
            null,
        );

        return redirect()->route('family.show', $junior)
            ->with('success', 'Pending handover cancelled.');
    }

    // ──────────────────────────────────────────────────────────────────────
    // Public handover acceptance (junior receives email & sets password)
    // ──────────────────────────────────────────────────────────────────────

    public function acceptHandover(string $token): View
    {
        $junior = $this->resolveHandoverToken($token);

        return view('family.accept-handover', compact('junior', 'token'));
    }

    public function completeHandover(Request $request, string $token): RedirectResponse
    {
        $junior = $this->resolveHandoverToken($token);

        $data = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);

        $oldEmail = $junior->email;

        $junior->update([
            'email' => $junior->handover_email,
            'password' => Hash::make($data['password']),
            'phone' => $data['phone'] ?? $junior->phone,
            'parent_id' => null,
            'is_managed_account' => false,
            'handover_email' => null,
            'handover_token' => null,
            'handover_expires_at' => null,
            'email_verified_at' => now(),
        ]);

        $this->auditLogService->log(
            $junior,
            'family.member.handover.completed',
            'User',
            $junior->id,
            ['old_email' => $oldEmail, 'parent_id' => $junior->getOriginal('parent_id')],
            ['email' => $junior->email],
        );

        \Illuminate\Support\Facades\Auth::login($junior);

        return redirect()->route('dashboard')
            ->with('success', 'Welcome to SAPRF! Your account is now under your control. All your past scores and registrations are still here.');
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────

    private function authorizeJunior(Request $request, User $junior): void
    {
        if (! $junior->isManaged() || $junior->parent_id !== $request->user()->id) {
            abort(403, 'You can only manage your own family accounts.');
        }
    }

    private function resolveHandoverToken(string $token): User
    {
        $hashed = hash('sha256', $token);

        $junior = User::query()
            ->where('handover_token', $hashed)
            ->where('handover_expires_at', '>', now())
            ->first();

        if (! $junior) {
            abort(404, 'This handover invitation is invalid or has expired. Please ask the person managing your account to send a new one.');
        }

        return $junior;
    }
}
