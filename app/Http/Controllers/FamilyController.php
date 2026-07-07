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
            ->juniors()
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
            // Format: junior-{slug}-{rand}@managed.saprf.co.za
            $slug = Str::slug($data['name']);
            $placeholderEmail = sprintf(
                'junior-%s-%s@managed.saprf.co.za',
                $slug ?: 'shooter',
                Str::lower(Str::random(6)),
            );

            $junior = User::create([
                'parent_id' => $parent->id,
                'is_managed_account' => true,
                'name' => $data['name'],
                'email' => $placeholderEmail,
                'password' => Hash::make(Str::random(40)), // unusable password
                'date_of_birth' => $data['date_of_birth'],
                'province_id' => $data['province_id'],
                'division_id' => $data['division_id'] ?? null,
                'email_verified_at' => now(), // managed accounts don't need OTP verification
            ]);

            $junior->assignRole('member');

            return $junior;
        });

        $this->auditLogService->log(
            $parent,
            'family.junior.created',
            'User',
            $junior->id,
            null,
            ['name' => $junior->name, 'parent_id' => $parent->id],
        );

        return redirect()->route('family.index')
            ->with('success', $junior->name . ' has been added to your family. You can now register them for matches.');
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
            'date_of_birth' => ['required', 'date', 'before:today', 'after:1995-01-01'],
            'province_id' => ['required', 'exists:provinces,id'],
            'division_id' => ['required', 'exists:divisions,id'],
        ]);

        $old = $junior->only(['name', 'date_of_birth', 'province_id', 'division_id']);

        $junior->update([
            'name' => $data['name'],
            'date_of_birth' => $data['date_of_birth'],
            'province_id' => $data['province_id'],
            'division_id' => $data['division_id'] ?? null,
        ]);

        $this->auditLogService->log(
            $request->user(),
            'family.junior.updated',
            'User',
            $junior->id,
            $old,
            $junior->only(['name', 'date_of_birth', 'province_id', 'division_id']),
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
            'handover_email.unique' => 'A user with this email already exists. The junior must use a fresh email address.',
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
            'family.junior.handover.started',
            'User',
            $junior->id,
            null,
            ['handover_email' => $data['handover_email']],
        );

        return redirect()->route('family.show', $junior)
            ->with('success', 'Handover invitation sent to ' . $data['handover_email'] . '. The link expires in ' . self::HANDOVER_TTL_DAYS . ' days.');
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
            'family.junior.handover.cancelled',
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
            'family.junior.handover.completed',
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
            abort(404, 'This handover invitation is invalid or has expired. Please ask your parent to send a new one.');
        }

        return $junior;
    }
}
