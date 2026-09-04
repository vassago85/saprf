<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Models\MatchEvent;
use App\Models\Membership;
use App\Models\MembershipFeeTier;
use App\Models\Province;
use App\Models\Score;
use App\Models\Standing;
use App\Models\User;
use App\Notifications\MemberInvitationNotification;
use App\Services\AuditLogService;
use App\Services\GreenQrCodePng;
use App\Services\ScoreValidationService;
use App\Services\SettingsService;
use App\Services\StandingsCalculationService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MembershipController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    public function myMembership(Request $request): View|RedirectResponse
    {
        $actor = $request->user();
        $user = $this->resolveManagedSubject($actor, $request->query('for_user'));
        $managingFamily = $user->id !== $actor->id;
        $membership = Membership::with('feeTier')->where('user_id', $user->id)->latest()->first();
        $paymentsEnabled = (bool) app(SettingsService::class)->get('payments_enabled', false);
        $canRenewEarly = $membership?->isWithinRenewalWindow() ?? false;

        // Age gate: joining/renewing requires a DOB on the applicant so we
        // can pick the correct tier (Junior under 18, Senior 65+, otherwise
        // Adult). Skip the redirect when the applicant already has an active
        // paid membership outside the renewal window — they're viewing
        // history, not renewing.
        $requiresPurchase = $paymentsEnabled && (
            ! ($membership && $membership->status === 'active' && $membership->payment_status === 'paid')
            || $canRenewEarly
        );

        if ($requiresPurchase && ! $user->date_of_birth) {
            return $this->missingDobRedirect($user, $actor);
        }

        $feeTiers = MembershipFeeTier::availableForUser($user);
        $age = $user->date_of_birth ? $user->getAgeOn(now()) : null;
        $storedTierSelectable = $membership?->feeTier
            && $membership->feeTier->is_active
            && $feeTiers->contains('id', $membership->feeTier->id)
            && $membership->feeTier->isAvailableForAge($age);
        $selectedTier = $storedTierSelectable
            ? $membership->feeTier
            : MembershipFeeTier::preferredForUser($user);

        // Amount shown on the pay/renew card: the membership's own tier if one
        // is stored, otherwise the default tier, otherwise the legacy setting.
        $fee = $selectedTier
            ? (float) $selectedTier->price
            : (float) app(SettingsService::class)->get('annual_membership_fee', 500);

        $seasons = Standing::distinct()->pluck('season')->sort()->reverse()->values();
        if ($seasons->isEmpty()) {
            $seasons = collect([(string) now()->year]);
        }

        return view('memberships.my-membership', compact(
            'membership',
            'user',
            'fee',
            'feeTiers',
            'selectedTier',
            'paymentsEnabled',
            'seasons',
            'managingFamily',
            'canRenewEarly',
        ));
    }

    /**
     * Redirect the applicant to the correct place to set their DOB before
     * we let them apply for membership. A managed junior goes to their
     * family-member edit page (the parent's the one holding the form); the
     * actor themselves goes to their own profile.
     */
    private function missingDobRedirect(User $subject, User $actor): RedirectResponse
    {
        $message = $subject->id === $actor->id
            ? 'Please set your date of birth before applying for membership so we can offer the correct rate.'
            : 'Please set '.$subject->name."'s date of birth before applying for membership so we can offer the correct rate.";

        $target = $subject->id === $actor->id
            ? route('profile')
            : route('family.edit', $subject);

        return redirect()->to($target)->with('error', $message);
    }

    private const SORTABLE = [
        'name' => 'users.name',
        'saprf_number' => 'memberships.saprf_number',
        'type' => 'memberships.membership_type',
        'province' => 'provinces.name',
        'expiry' => 'memberships.expiry_date',
    ];

    public function index(Request $request): View
    {
        $user = $request->user();
        $isAdmin = $user->hasAnyRole(['developer', 'exco', 'owner', 'admin']);

        if (! $isAdmin) {
            $memberships = Membership::query()->where('user_id', $user->id)->with('user')->paginate(20);

            return view('memberships.index', [
                'memberships' => $memberships,
                'isAdmin' => false,
                'provinces' => collect(),
                'filters' => [],
                'sort' => 'name',
                'dir' => 'asc',
                'pendingInviteCount' => 0,
            ]);
        }

        $memberships = $this->buildIndexQuery($request)->paginate(25)->withQueryString();

        return view('memberships.index', [
            'memberships' => $memberships,
            'isAdmin' => true,
            'provinces' => \App\Models\Province::orderBy('name')->get(),
            'filters' => $request->only(['search', 'status', 'province_id']),
            'sort' => $this->resolveSort($request),
            'dir' => $this->resolveDir($request),
            'pendingInviteCount' => $this->pendingInvitationQuery()->count(),
        ]);
    }

    public function downloadCsv(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        abort_unless($request->user()->hasAnyRole(['developer', 'exco', 'owner', 'admin']), 403);

        $memberships = $this->buildIndexQuery($request)->get();
        $filename = 'Memberships_'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($memberships) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Member', 'Email', 'Phone', 'SAPRF Number', 'Type', 'Status', 'Province', 'Start Date', 'Expiry Date']);
            foreach ($memberships as $m) {
                fputcsv($handle, [
                    $m->user?->name ?? '',
                    $m->user?->email ?? '',
                    $m->user?->phone ?? '',
                    $m->saprf_number ?? '',
                    ucfirst((string) $m->membership_type),
                    $m->effective_status_label,
                    $m->user?->province?->name ?? '',
                    $m->start_date?->format('Y-m-d') ?? '',
                    $m->expiry_date?->format('Y-m-d') ?? '',
                ]);
            }
            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    private function buildIndexQuery(Request $request)
    {
        $query = Membership::query()
            ->with(['user.province'])
            ->join('users', 'users.id', '=', 'memberships.user_id')
            ->leftJoin('provinces', 'provinces.id', '=', 'users.province_id')
            ->whereNull('users.deleted_at')
            ->select('memberships.*');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('users.name', 'like', "%{$search}%")
                    ->orWhere('users.email', 'like', "%{$search}%")
                    ->orWhere('memberships.saprf_number', 'like', "%{$search}%");
            });
        }

        // Filter by the same simplified status we display: active vs expired,
        // plus the non-member / revoked overrides — all derived from the
        // expiry date and type, not the noisy legacy status flags.
        $today = now()->startOfDay()->toDateString();
        match ($request->input('status')) {
            'active' => $query->where('memberships.membership_type', '!=', 'free')
                ->where('memberships.status', '!=', 'revoked')
                ->where(function ($q) use ($today) {
                    $q->whereNull('memberships.expiry_date')
                        ->orWhereDate('memberships.expiry_date', '>=', $today);
                }),
            'expired' => $query->where('memberships.membership_type', '!=', 'free')
                ->where('memberships.status', '!=', 'revoked')
                ->whereDate('memberships.expiry_date', '<', $today),
            'non_member' => $query->where('memberships.membership_type', 'free'),
            'revoked' => $query->where('memberships.status', 'revoked'),
            default => null,
        };

        if ($provinceId = $request->input('province_id')) {
            $query->where('users.province_id', $provinceId);
        }

        return $query->orderBy(self::SORTABLE[$this->resolveSort($request)], $this->resolveDir($request));
    }

    private function resolveSort(Request $request): string
    {
        $sort = (string) $request->input('sort', 'name');

        return array_key_exists($sort, self::SORTABLE) ? $sort : 'name';
    }

    private function resolveDir(Request $request): string
    {
        return strtolower((string) $request->input('dir')) === 'desc' ? 'desc' : 'asc';
    }

    public function show(Membership $membership): View
    {
        $this->authorize('view', $membership);

        $membership->load(['user.province', 'user.club', 'payments', 'revokedByUser']);

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
            'status' => ['required', 'in:active,pending,lapsed,expired,revoked'],
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

        $clubs = Club::query()
            ->where('is_active', true)
            ->with('province:id,name')
            ->orderBy('name')
            ->get()
            ->groupBy(fn (Club $c) => $c->province?->name ?? 'Unassigned');

        return view('memberships.edit', [
            'membership' => $membership,
            'provinces' => Province::orderBy('name')->get(),
            'clubs' => $clubs,
            'countries' => User::COUNTRY_OPTIONS,
            'genderOptions' => User::GENDER_OPTIONS,
            'ethnicityOptions' => User::ETHNICITY_OPTIONS,
        ]);
    }

    public function update(
        Request $request,
        Membership $membership,
        ScoreValidationService $scoreValidation,
        StandingsCalculationService $standings,
    ): RedirectResponse {
        $this->authorize('update', $membership);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($membership->user_id)],
            'phone' => ['nullable', 'string', 'max:20'],
            'sa_id_number' => ['nullable', 'string', 'digits:13', Rule::unique('users', 'sa_id_number')->ignore($membership->user_id)],
            'mil_le_number' => ['nullable', 'string', 'max:50'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', Rule::in(array_keys(User::GENDER_OPTIONS))],
            'ethnicity' => ['nullable', Rule::in(array_keys(User::ETHNICITY_OPTIONS))],
            'previously_disadvantaged_choice' => ['nullable', Rule::in(['yes', 'no'])],
            'province_id' => ['nullable', 'exists:provinces,id'],
            'club_id' => ['nullable', 'exists:clubs,id'],
            'sa_citizen' => ['nullable', Rule::in(['0', '1'])],
            'country_of_residence' => ['nullable', Rule::in(array_keys(User::COUNTRY_OPTIONS))],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'address_line_3' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'saprf_number' => ['nullable', 'string', 'max:100', Rule::unique('memberships', 'saprf_number')->ignore($membership->id)],
            'membership_type' => ['required', 'string', 'max:50'],
            'status' => ['required', 'in:pending,active,lapsed,suspended,expired,revoked'],
            'payment_status' => ['required', 'in:unpaid,pending,paid,partial,overdue,waived'],
            'start_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date'],
        ]);

        $user = $membership->user;
        if ($user) {
            $userUpdates = [
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                'sa_id_number' => $validated['sa_id_number'] ?? null,
                'mil_le_number' => $validated['mil_le_number'] ?? null,
                'date_of_birth' => $validated['date_of_birth'] ?? null,
                'gender' => $validated['gender'] ?? null,
                'ethnicity' => $validated['ethnicity'] ?? null,
                'previously_disadvantaged' => match ($validated['previously_disadvantaged_choice'] ?? '') {
                    'yes' => true,
                    'no' => false,
                    default => null,
                },
                'province_id' => isset($validated['province_id']) ? (int) $validated['province_id'] : null,
                'club_id' => isset($validated['club_id']) ? (int) $validated['club_id'] : null,
                'sa_citizen' => match ($validated['sa_citizen'] ?? null) {
                    '1' => true,
                    '0' => false,
                    default => null,
                },
                'country_of_residence' => $validated['country_of_residence'] ?? null,
                'address_line_1' => $validated['address_line_1'] ?? null,
                'address_line_2' => $validated['address_line_2'] ?? null,
                'address_line_3' => $validated['address_line_3'] ?? null,
                'city' => $validated['city'] ?? null,
                'postal_code' => $validated['postal_code'] ?? null,
            ];

            $profileFields = array_keys($userUpdates);
            $oldProfile = $user->only($profileFields);
            $user->update($userUpdates);

            if ($user->wasChanged($profileFields)) {
                $this->auditLogService->log(
                    $request->user(),
                    $user->wasChanged('email') ? 'user.email_updated' : 'user.profile_updated',
                    'User',
                    $user->id,
                    $oldProfile,
                    $user->only($profileFields),
                );
            }
        }

        $trackedFields = ['saprf_number', 'membership_type', 'status', 'payment_status', 'start_date', 'expiry_date'];
        $old = $membership->only($trackedFields);
        $membership->update([
            // Keep the existing number if the field was cleared, so we never
            // wipe a member's SAPRF number by accident.
            'saprf_number' => ($validated['saprf_number'] ?? null) ?: $membership->saprf_number,
            'membership_type' => $validated['membership_type'],
            'status' => $validated['status'],
            'payment_status' => $validated['payment_status'],
            'start_date' => $validated['start_date'] ?? null,
            'expiry_date' => $validated['expiry_date'] ?? null,
        ]);

        $this->auditLogService->log(
            $request->user(),
            'membership.updated',
            'Membership',
            $membership->id,
            $old,
            $membership->only($trackedFields),
        );

        // Expanding/correcting the paid window (or flipping status/payment)
        // must reclassify this shooter's scores — MembershipObserver only auto-
        // promotes pending on activate/pay, and leaves expiry-only expansions
        // to an explicit reevaluation path.
        if ($membership->user_id) {
            $affectedMatchIds = $scoreValidation->reevaluateScoresForUser($membership->user_id);
            foreach (MatchEvent::whereIn('id', $affectedMatchIds)->get() as $match) {
                $standings->recalculateForMatch($match);
            }
        }

        return redirect()->route('memberships.show', $membership)
            ->with('success', 'Membership updated successfully.');
    }

    /**
     * Delete the member account tied to this membership. Intended for cleaning
     * up duplicate imported accounts. Soft-deletes the user (restorable from the
     * user-management deleted list); the membership row is left intact.
     */
    public function destroy(Request $request, Membership $membership): RedirectResponse
    {
        $this->authorize('delete', $membership);

        $actor = $request->user();
        $user = $membership->user;

        if ($user && $user->hasRole('owner')) {
            return back()->with('error', 'Cannot delete an owner account.');
        }

        if ($user && $actor && $user->id === $actor->id) {
            return back()->with('error', 'You cannot delete your own account.');
        }

        $name = $user?->name ?? 'Unknown';
        $email = $user?->email;

        $this->auditLogService->log(
            $actor,
            'user.soft_deleted',
            'User',
            $user?->id,
            ['name' => $name, 'email' => $email, 'reason' => 'Duplicate account removed from membership details'],
            null,
        );

        // Soft-delete the user only; the membership row is left intact so a
        // restore from User Management brings the whole account back. The index
        // hides memberships whose user is trashed, so it disappears from lists.
        $user?->delete();

        return redirect()->route('memberships.index')
            ->with('success', "{$name} has been deleted. You can restore them from the deleted users list in User Management.");
    }

    public function revoke(Request $request, Membership $membership): RedirectResponse
    {
        $this->authorize('update', $membership);

        $validated = $request->validate([
            'revocation_reason' => ['required', 'string', 'max:1000'],
        ]);

        $old = $membership->only(['status', 'revoked_at', 'revocation_reason', 'revoked_by']);

        $membership->update([
            'status' => 'revoked',
            'revoked_at' => now(),
            'revocation_reason' => $validated['revocation_reason'],
            'revoked_by' => $request->user()->id,
        ]);

        $this->auditLogService->log(
            $request->user(),
            'membership.revoked',
            'Membership',
            $membership->id,
            $old,
            [
                'status' => 'revoked',
                'revocation_reason' => $validated['revocation_reason'],
            ],
        );

        return redirect()->route('memberships.show', $membership)
            ->with('success', 'Membership has been revoked.');
    }

    public function reinstate(Request $request, Membership $membership): RedirectResponse
    {
        $this->authorize('update', $membership);

        if (! $membership->isRevoked()) {
            return back()->with('error', 'This membership is not revoked.');
        }

        $old = $membership->only(['status', 'revoked_at', 'revocation_reason', 'revoked_by']);

        $membership->update([
            'status' => 'active',
            'revoked_at' => null,
            'revocation_reason' => null,
            'revoked_by' => null,
        ]);

        $this->auditLogService->log(
            $request->user(),
            'membership.reinstated',
            'Membership',
            $membership->id,
            $old,
            ['status' => 'active'],
        );

        return redirect()->route('memberships.show', $membership)
            ->with('success', 'Membership has been reinstated.');
    }

    /**
     * Set a temporary password on a member's account so an admin can hand it
     * to them out-of-band. Used when the member is not receiving invitation
     * or password-reset emails (deliverability failure, wrong address, spam
     * folder, etc.) and we need to unblock their login without waiting for
     * mail to work.
     *
     * The plaintext password is:
     *   - hashed before it hits the DB (like any other password),
     *   - flashed to the session for ONE render on the redirect target so the
     *     operator can copy it (never written to logs, never re-shown),
     *   - never emailed anywhere.
     *
     * The member is force-flagged with `must_change_password=true` so
     * `ForcePasswordChange` redirects them to the reset form on next login.
     * A `user.admin_password_reset` AuditLog entry records who did it and
     * why, without the password itself.
     */
    public function resetPassword(Request $request, Membership $membership): RedirectResponse
    {
        $actor = $request->user();

        abort_unless($actor->hasAnyRole(['developer', 'exco', 'owner', 'admin']), 403);

        $member = $membership->user;
        if (! $member) {
            return back()->with('error', 'No user account is linked to this membership.');
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            // Optional custom password. Leaving it blank generates a strong
            // 16-char alphanumeric — long enough for real security but easy
            // to relay by phone/WhatsApp without special characters getting
            // mangled.
            'custom_password' => ['nullable', 'string', 'min:12', 'max:64'],
        ]);

        $custom = $validated['custom_password'] ?? null;
        $tempPassword = ($custom !== null && $custom !== '')
            ? $custom
            : Str::password(length: 16, letters: true, numbers: true, symbols: false, spaces: false);

        $member->forceFill([
            'password' => Hash::make($tempPassword),
            'must_change_password' => true,
        ])->save();

        $this->auditLogService->log(
            $actor,
            'user.admin_password_reset',
            'User',
            $member->id,
            null,
            // Do NOT store the plaintext or hash in the audit — the log
            // records that a reset happened and why, nothing more.
            ['reason' => $validated['reason']],
            "Admin reset password for {$member->email} — force change on next login",
        );

        return redirect()->route('memberships.show', $membership)
            ->with('temp_password', $tempPassword)
            ->with('temp_password_for', $member->name)
            ->with('temp_password_reason', $validated['reason'])
            ->with('success', "Temporary password set for {$member->name}. Copy it now — it will not be shown again.");
    }

    // ── Member Invitations ──

    /**
     * Send (or re-send) a platform invitation to a single member.
     */
    public function invite(Request $request, Membership $membership): RedirectResponse
    {
        abort_unless($request->user()->hasAnyRole(['developer', 'exco', 'owner', 'admin']), 403);

        $member = $membership->user;

        if (! $member || blank($member->email)) {
            return back()->with('error', 'This member has no email address on file.');
        }

        if ($member->is_managed_account) {
            return back()->with('error', 'Managed family accounts are activated by their guardian, not by invitation.');
        }

        $this->dispatchInvitation($request->user(), $member);

        return back()->with('success', "Invitation sent to {$member->email}.");
    }

    /**
     * Bulk-invite every member who has not yet activated their account.
     */
    public function invitePending(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasAnyRole(['developer', 'exco', 'owner', 'admin']), 403);

        $members = $this->pendingInvitationQuery()->get();

        foreach ($members as $member) {
            $this->dispatchInvitation($request->user(), $member);
        }

        $count = $members->count();

        if ($count === 0) {
            return back()->with('success', 'Every member has already activated their account — no invitations needed.');
        }

        return back()->with('success', "Invitations queued for {$count} member".($count === 1 ? '' : 's').'.');
    }

    private function dispatchInvitation(User $actor, User $member): void
    {
        $token = $member->generateInvitationToken();

        $member->notify(new MemberInvitationNotification($token));

        $this->auditLogService->log(
            $actor,
            'member.invitation.sent',
            'User',
            $member->id,
            null,
            ['email' => $member->email],
        );
    }

    /**
     * Members eligible for a platform invitation: real (non-managed) accounts
     * with an email who have not yet verified their email or still hold a
     * starter password that must be changed.
     */
    private function pendingInvitationQuery()
    {
        return User::query()
            ->where('is_managed_account', false)
            ->whereNotNull('email')
            ->where(function ($q) {
                $q->whereNull('email_verified_at')
                    ->orWhere('must_change_password', true);
            });
    }

    // ── Public Verification ──

    public function verify(string $saprfNumber): View
    {
        $membership = Membership::with('user')
            ->where('saprf_number', $saprfNumber)
            ->latest()
            ->first();

        return view('memberships.verify', compact('membership'));
    }

    // ── Certificate PDF ──

    public function certificate(Request $request): Response
    {
        $user = $this->resolveManagedSubject($request->user(), $request->query('for_user'));
        $user->load('province');
        $membership = Membership::where('user_id', $user->id)
            ->where('status', 'active')
            ->where('payment_status', 'paid')
            ->latest()
            ->firstOrFail();

        $verifyUrl = url("/verify/{$membership->saprf_number}");
        $qrBase64 = app(GreenQrCodePng::class)->toDataUri($verifyUrl, 240);

        $status = $membership->effective_status;
        $statusLabel = match ($status) {
            'active' => 'ACTIVE MEMBER',
            'expired' => 'EXPIRED MEMBER',
            'revoked' => 'REVOKED',
            'pending' => 'PENDING',
            'non_member' => 'NON-MEMBER',
            default => strtoupper($membership->effective_status_label),
        };
        $chipMuted = in_array($status, ['expired', 'revoked', 'pending', 'non_member'], true)
            || str_contains(strtolower((string) $membership->status), 'suspend');

        // Article for the "is a/an …" certify sentence.
        $statusArticle = in_array(strtoupper(substr($statusLabel, 0, 1)), ['A', 'E', 'I', 'O', 'U'], true) ? 'is an' : 'is a';

        $nameLen = mb_strlen((string) $user->name);
        // Sized to keep the certify card + QR on a single A4 page under DomPDF.
        $memberNameSize = match (true) {
            $nameLen >= 28 => '16pt',
            $nameLen >= 22 => '18pt',
            $nameLen >= 16 => '20pt',
            default => '22pt',
        };

        $generatedAt = now()->timezone('Africa/Johannesburg');

        $viewData = [
            'user' => $user,
            'membership' => $membership,
            'qrBase64' => $qrBase64,
            'logoBase64' => $this->certificateAssetDataUri(public_path('saprf-logo-black-text.png')),
            'frameBase64' => $this->certificateAssetDataUri(public_path('images/certificates/saprf-frame-a4.png')),
            'verifyUrl' => $verifyUrl,
            'statusLabel' => $statusLabel,
            'statusArticle' => $statusArticle,
            'chipMuted' => $chipMuted,
            'memberNameSize' => $memberNameSize,
            'generatedDate' => $generatedAt->format('d M Y'),
            'generatedTime' => $generatedAt->format('H:i'),
            'certBuild' => '20260723i',
            'recordRows' => array_values(array_filter([
                ['label' => 'SAPRF NO', 'value' => $membership->saprf_number ?: '—'],
                ['label' => 'MEMBERSHIP', 'value' => ucfirst((string) ($membership->membership_type ?? 'Standard'))],
                $membership->start_date
                    ? ['label' => 'MEMBER SINCE', 'value' => $membership->start_date->format('d M Y')]
                    : null,
                ['label' => 'VALID UNTIL', 'value' => $membership->expiry_date?->format('d M Y') ?? '—'],
            ])),
        ];

        $filename = "SAPRF-Certificate-{$membership->saprf_number}.pdf";
        $html = view('memberships.certificate-pdf', $viewData)->render();

        return $this->downloadHtmlAsPdf($html, $filename);
    }

    // ── Activity Report PDF ──

    public function activityReport(Request $request): Response
    {
        $user = $this->resolveManagedSubject($request->user(), $request->query('for_user'));
        $user->load('province');
        $membership = Membership::where('user_id', $user->id)->latest()->first();

        abort_unless($membership, 404, 'No membership found.');

        $season = $request->input('season', (string) now()->year);
        $includeStandings = $request->boolean('include_standings', false);

        $scores = Score::with(['match.province', 'division'])
            ->where('user_id', $user->id)
            ->whereHas('match', fn ($q) => $q->where('season', $season)->where('status', 'completed'))
            ->where('status', 'valid')
            ->orderBy('match_date')
            ->get();

        $standingsSummary = [];
        if ($includeStandings) {
            foreach (['PRS', 'PR22'] as $series) {
                $overall = Standing::where('user_id', $user->id)
                    ->where('season', $season)
                    ->where('series', $series)
                    ->whereNull('province_id')
                    ->whereNull('division_id')
                    ->first();

                if ($overall) {
                    $divisionStanding = Standing::where('user_id', $user->id)
                        ->where('season', $season)
                        ->where('series', $series)
                        ->whereNull('province_id')
                        ->whereNotNull('division_id')
                        ->with('division')
                        ->first();

                    $standingsSummary[] = [
                        'series' => $series,
                        'overall_rank' => $overall->rank,
                        'overall_points' => $overall->points,
                        'division_name' => $divisionStanding?->division?->name,
                        'division_rank' => $divisionStanding?->rank,
                        'division_points' => $divisionStanding?->points,
                    ];
                }
            }
        }

        $verifyUrl = url("/verify/{$membership->saprf_number}");
        $qrBase64 = app(GreenQrCodePng::class)->toDataUri($verifyUrl, 200);

        $status = $membership->effective_status;
        $statusLabel = match ($status) {
            'active' => 'ACTIVE MEMBER',
            'expired' => 'EXPIRED MEMBER',
            'revoked' => 'REVOKED',
            'pending' => 'PENDING',
            'non_member' => 'NON-MEMBER',
            default => strtoupper($membership->effective_status_label),
        };
        $chipMuted = in_array($status, ['expired', 'revoked', 'pending', 'non_member'], true)
            || str_contains(strtolower((string) $membership->status), 'suspend');

        $nameLen = mb_strlen((string) $user->name);
        $memberNameSize = match (true) {
            $nameLen >= 28 => '18pt',
            $nameLen >= 22 => '20pt',
            $nameLen >= 18 => '22pt',
            default => '24pt',
        };

        $generatedAt = now()->timezone('Africa/Johannesburg');

        $html = view('memberships.activity-report-pdf', [
            'user' => $user,
            'membership' => $membership,
            'season' => $season,
            'scores' => $scores,
            'includeStandings' => $includeStandings,
            'standingsSummary' => $standingsSummary,
            'qrBase64' => $qrBase64,
            'logoBase64' => $this->certificateAssetDataUri(public_path('saprf-logo-black-text.png')),
            'frameBase64' => $this->certificateAssetDataUri(public_path('images/certificates/saprf-frame-a4.png')),
            'verifyUrl' => $verifyUrl,
            'statusLabel' => $statusLabel,
            'chipMuted' => $chipMuted,
            'memberNameSize' => $memberNameSize,
            'generatedDate' => $generatedAt->format('d M Y'),
            'generatedTime' => $generatedAt->format('H:i'),
            'stats' => [
                'total' => $scores->count(),
                'prs' => $scores->filter(fn ($s) => in_array($s->match?->series ?? $s->match?->match_type, ['PRS'], true))->count(),
                'pr22' => $scores->filter(fn ($s) => in_array($s->match?->series ?? $s->match?->match_type, ['PR22'], true))->count(),
            ],
        ])->render();

        $filename = "SAPRF-Activity-Report-{$membership->saprf_number}-{$season}.pdf";

        return $this->downloadHtmlAsPdf($html, $filename);
    }

    private function downloadHtmlAsPdf(string $html, string $filename): Response
    {
        // DomPDF is memory / CPU heavy — bump the ceilings so a slow render
        // doesn't get killed by php-fpm (which upstream nginx surfaces as a
        // 502 / 503 rather than a clean Laravel error page).
        @ini_set('memory_limit', '512M');
        @set_time_limit(120);

        try {
            $fontDir = $this->prepareCertificateFontDirectory();

            $pdf = Pdf::loadHTML($html)
                ->setPaper('A4', 'portrait')
                ->setOption('isRemoteEnabled', false)
                ->setOption('isHtml5ParserEnabled', true)
                ->setOption('isFontSubsettingEnabled', false)
                ->setOption('chroot', base_path())
                ->setOption('fontDir', $fontDir)
                ->setOption('fontCache', $fontDir);

            // Pre-register TTFs into the writable cache so DomPDF never tries to
            // write .ufm metrics under resources/fonts (not writable by www-data).
            $this->registerCertificateFonts($pdf, $fontDir);

            return $pdf->download($filename);
        } catch (\Throwable $e) {
            // Log the underlying failure with enough context to diagnose from
            // the server, then surface a clean 500 (rather than a silent
            // worker crash that nginx turns into 502/503).
            Log::error('PDF render failed', [
                'filename' => $filename,
                'user_id' => Auth::id(),
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'trace' => collect($e->getTrace())->take(8)->all(),
            ]);

            abort(500, 'Unable to generate PDF. Please try again shortly — if the problem persists, contact support.');
        }
    }

    /**
     * DomPDF must read TTFs and write .ufm metrics in the same writable directory.
     * Source fonts live in resources/; we sync copies into storage/fonts at render time.
     */
    private function prepareCertificateFontDirectory(): string
    {
        $sourceDir = resource_path('fonts/certificates');
        $fontDir = storage_path('fonts');

        if (! is_dir($fontDir) && ! mkdir($fontDir, 0775, true) && ! is_dir($fontDir)) {
            throw new \RuntimeException('Unable to create DomPDF font directory: '.$fontDir);
        }

        if (! is_writable($fontDir)) {
            @chmod($fontDir, 0775);
        }

        foreach (glob($sourceDir.DIRECTORY_SEPARATOR.'*.ttf') ?: [] as $sourceFont) {
            $destination = $fontDir.DIRECTORY_SEPARATOR.basename($sourceFont);
            if (! is_file($destination) || filemtime($sourceFont) > filemtime($destination)) {
                if (! @copy($sourceFont, $destination)) {
                    throw new \RuntimeException('Unable to sync certificate font: '.basename($sourceFont));
                }
                @chmod($destination, 0664);
            }
        }

        // Remove any stale metrics DomPDF may have tried to write under
        // resources/. Two plain globs beat GLOB_BRACE because Alpine's musl
        // libc doesn't define it — PHP on Alpine leaves the constant unset
        // and the whole certificate download 500s.
        foreach (['*.ufm', '*.json'] as $pattern) {
            foreach (glob($sourceDir.DIRECTORY_SEPARATOR.$pattern) ?: [] as $stale) {
                @unlink($stale);
            }
        }

        return $fontDir;
    }

    private function registerCertificateFonts(\Barryvdh\DomPDF\PDF $pdf, string $fontDir): void
    {
        $dompdf = $pdf->getDomPDF();
        $metrics = $dompdf->getFontMetrics();

        $fonts = [
            ['family' => 'Saira Condensed', 'weight' => '600', 'style' => 'normal', 'file' => 'SairaCondensed-SemiBold.ttf'],
            ['family' => 'Saira Condensed', 'weight' => '700', 'style' => 'normal', 'file' => 'SairaCondensed-Bold.ttf'],
            ['family' => 'Saira Condensed', 'weight' => 'bold', 'style' => 'normal', 'file' => 'SairaCondensed-Bold.ttf'],
            ['family' => 'Saira', 'weight' => '600', 'style' => 'normal', 'file' => 'Saira-SemiBold.ttf'],
            ['family' => 'Saira', 'weight' => 'bold', 'style' => 'normal', 'file' => 'Saira-SemiBold.ttf'],
            ['family' => 'IBM Plex Mono', 'weight' => '400', 'style' => 'normal', 'file' => 'IBMPlexMono-Regular.ttf'],
            ['family' => 'IBM Plex Mono', 'weight' => 'normal', 'style' => 'normal', 'file' => 'IBMPlexMono-Regular.ttf'],
            ['family' => 'IBM Plex Mono', 'weight' => '500', 'style' => 'normal', 'file' => 'IBMPlexMono-Medium.ttf'],
            ['family' => 'IBM Plex Mono', 'weight' => '600', 'style' => 'normal', 'file' => 'IBMPlexMono-SemiBold.ttf'],
            ['family' => 'IBM Plex Mono', 'weight' => 'bold', 'style' => 'normal', 'file' => 'IBMPlexMono-SemiBold.ttf'],
        ];

        foreach ($fonts as $font) {
            $path = $fontDir.DIRECTORY_SEPARATOR.$font['file'];
            if (! is_file($path)) {
                continue;
            }

            $metrics->registerFont([
                'family' => $font['family'],
                'weight' => $font['weight'],
                'style' => $font['style'],
            ], $path);
        }
    }

    /**
     * The signed-in member, or one of their managed family accounts when
     * `for_user` is present. Foreign accounts are rejected.
     */
    private function resolveManagedSubject(User $actor, mixed $forUserId): User
    {
        if ($forUserId === null || $forUserId === '') {
            return $actor;
        }

        $subject = $actor->findManagedAccount($forUserId);
        abort_unless($subject, 403, 'You can only manage your own family accounts.');

        return $subject;
    }

    private function certificateAssetDataUri(string $absolutePath): string
    {
        $binary = @file_get_contents($absolutePath);
        if ($binary === false || $binary === '') {
            throw new \RuntimeException('Missing certificate asset: '.$absolutePath);
        }

        $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
        $mime = match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'image/png',
        };

        return 'data:'.$mime.';base64,'.base64_encode($binary);
    }
}
