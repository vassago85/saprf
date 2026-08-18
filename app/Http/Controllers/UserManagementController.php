<?php

namespace App\Http\Controllers;

use App\Models\Membership;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\ScoreValidationService;
use App\Services\StandingsCalculationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UserManagementController extends Controller
{
    /**
     * Roles anyone with access to user management can assign. Deliberately
     * kept small so owners can delegate day-to-day admin without accidentally
     * escalating another user to federation-level power.
     */
    private const STANDARD_ASSIGNABLE_ROLES = ['member', 'match_director', 'admin'];

    /**
     * Roles that only a developer (sysadmin) may assign. These are elevated:
     * owner/exco are federation-wide bypass roles, chair is the federation
     * chair (always paired with exco — see updateRole), provincial_admin
     * has province-scoped power, and developer is the sysadmin superuser.
     */
    private const ELEVATED_ASSIGNABLE_ROLES = ['provincial_admin', 'exco', 'chair', 'owner', 'developer'];

    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    public function index(Request $request): View
    {
        $query = User::with(['roles', 'membership']);

        $showTrashed = $request->boolean('trashed');
        if ($showTrashed) {
            $query->onlyTrashed();
        }

        $search = $request->input('search');
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhereHas('membership', fn ($mq) => $mq->where('saprf_number', 'like', "%{$search}%"));
            });
        }

        $users = $query->orderBy('name')->paginate(20)->withQueryString();
        $trashedCount = User::onlyTrashed()->count();

        return view('user-management.index', compact('users', 'search', 'showTrashed', 'trashedCount'));
    }

    public function edit(Request $request, User $user): View
    {
        $user->load('roles', 'membership');
        $assignableRoles = self::STANDARD_ASSIGNABLE_ROLES;
        // Developers see the elevated tier as an "advanced" section so they
        // can manage sysadmin/federation-level access without dropping to
        // tinker. Non-developers never see the elevated checkboxes.
        $elevatedRoles = $request->user()->hasRole('developer')
            ? self::ELEVATED_ASSIGNABLE_ROLES
            : [];

        return view('user-management.edit', compact('user', 'assignableRoles', 'elevatedRoles'));
    }

    public function updateMembership(
        Request $request,
        User $user,
        ScoreValidationService $scoreValidation,
        StandingsCalculationService $standings,
    ): RedirectResponse {
        $validated = $request->validate([
            'membership_type' => ['nullable', 'string', 'max:50'],
            'status' => ['required', 'in:active,pending,lapsed,expired,revoked'],
            'payment_status' => ['required', 'in:paid,unpaid,waived'],
            'saprf_number' => ['nullable', 'string', 'max:100'],
            'start_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date'],
        ]);

        // A "free" registrant is a non-member by definition — never let it be
        // saved as paid, whatever the form said.
        if (($validated['membership_type'] ?? null) === 'free') {
            $validated['payment_status'] = 'unpaid';
        }

        $membership = $user->membership;
        $old = $membership
            ? $membership->only(['membership_type', 'status', 'payment_status', 'saprf_number', 'start_date', 'expiry_date'])
            : null;

        if (! $membership) {
            $saprf = $validated['saprf_number'] ?: 'SAPRF-IMPORT-'.strtoupper(\Illuminate\Support\Str::random(6));
            $membership = new Membership(['user_id' => $user->id, 'saprf_number' => $saprf]);
        }

        $membership->fill([
            'membership_type' => $validated['membership_type'] ?: $membership->membership_type,
            'status' => $validated['status'],
            'payment_status' => $validated['payment_status'],
            'start_date' => $validated['start_date'] ?: null,
            'expiry_date' => $validated['expiry_date'] ?: null,
        ]);
        if (! empty($validated['saprf_number'])) {
            $membership->saprf_number = $validated['saprf_number'];
        }
        $membership->save();

        // The change may flip this shooter's scores between valid/non_member/
        // lapsed, so re-evaluate them and rebuild the affected standings.
        $affectedMatchIds = [];
        $user->load('membership');
        foreach ($user->scores()->with('match')->get() as $score) {
            $before = $score->status;
            $scoreValidation->evaluateScoreStatus($score);
            if ($score->status !== $before && $score->match) {
                $affectedMatchIds[$score->match_id] = $score->match;
            }
        }
        foreach ($affectedMatchIds as $match) {
            $standings->recalculateForMatch($match);
        }

        $this->auditLogService->log(
            $request->user(),
            'membership.updated',
            'Membership',
            $membership->id,
            $old,
            $membership->only(['membership_type', 'status', 'payment_status', 'saprf_number', 'start_date', 'expiry_date']),
            'Membership updated via user management.',
        );

        return redirect()->route('user-management.edit', $user)
            ->with('success', "{$user->name}'s membership updated.");
    }

    public function updateRole(Request $request, User $user): RedirectResponse
    {
        $actor = $request->user();
        $isDeveloper = $actor->hasRole('developer');

        // Developers get the elevated tier; everyone else is restricted to the
        // standard three. The validator refuses anything outside this list so
        // hand-crafted POSTs can't sneak elevated roles through.
        $allowedRoles = array_merge(
            self::STANDARD_ASSIGNABLE_ROLES,
            $isDeveloper ? self::ELEVATED_ASSIGNABLE_ROLES : [],
        );

        $validated = $request->validate([
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', 'in:' . implode(',', $allowedRoles)],
        ]);

        // Self-edit guards: an owner or developer may edit their own roles
        // (they're the top of the tree and need a way out of a bad state);
        // everyone else must ask someone higher up.
        if ($user->id === $actor->id && !$actor->hasRole('owner') && !$isDeveloper) {
            return back()->with('error', 'You cannot change your own roles.');
        }

        // A developer may edit another owner's roles (that's what sysadmin is
        // for); anyone else is blocked from touching an owner account.
        if ($user->hasRole('owner') && $user->id !== $actor->id && !$isDeveloper) {
            return back()->with('error', 'Cannot change the roles of another owner.');
        }

        // A developer must never lock themselves out — keep the developer
        // role on their own account no matter what the form submitted.
        if ($user->id === $actor->id && $isDeveloper) {
            $validated['roles'][] = 'developer';
        }

        $oldRoles = $user->getRoleNames()->toArray();
        $newRoles = $validated['roles'];

        // Non-developer editors can never demote an owner. Developers CAN
        // (they can drop 'owner' by unchecking it in the elevated section).
        if ($user->hasRole('owner') && !$isDeveloper) {
            $newRoles[] = 'owner';
        }

        // Chair implies Exco — the Notification Centre and (future) Exco
        // Records Vault both assume any chair also has the standard Exco
        // power set. Silently union the roles rather than reject the form
        // so operators never sit staring at a validation error asking why.
        if (in_array('chair', $newRoles, true)) {
            $newRoles[] = 'exco';
        }

        $user->syncRoles(array_unique($newRoles));

        $this->auditLogService->log(
            $actor,
            'roles_changed',
            'User',
            $user->id,
            ['roles' => $oldRoles],
            ['roles' => $newRoles],
            'Roles changed from [' . implode(', ', $oldRoles) . '] to [' . implode(', ', $newRoles) . ']',
        );

        return redirect()->route('user-management.index')
            ->with('success', "{$user->name}'s roles updated.");
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $actor = $request->user();

        if ($user->hasRole('owner')) {
            return back()->with('error', 'Cannot delete an owner account.');
        }

        if ($user->id === $actor->id) {
            return back()->with('error', 'You cannot delete your own account.');
        }

        $name = $user->name;

        $this->auditLogService->log(
            $actor,
            'user.soft_deleted',
            'User',
            $user->id,
            ['name' => $name, 'email' => $user->email],
            null,
        );

        $user->delete();

        return redirect()->route('user-management.index')
            ->with('success', "{$name} has been deleted. You can restore them from the deleted users list.");
    }

    public function restore(Request $request, int $id): RedirectResponse
    {
        $user = User::onlyTrashed()->findOrFail($id);

        $this->auditLogService->log(
            $request->user(),
            'user.restored',
            'User',
            $user->id,
            null,
            ['name' => $user->name, 'email' => $user->email],
        );

        $user->restore();

        return redirect()->route('user-management.index')
            ->with('success', "{$user->name} has been restored.");
    }

    public function confirmForceDelete(Request $request, int $id): View
    {
        $user = User::onlyTrashed()->findOrFail($id);

        $impact = [
            'scores' => $user->scores()->count(),
            'registrations' => $user->matchRegistrations()->count(),
            'rifles' => $user->rifleConfigurations()->count(),
            'ammo_loads' => $user->ammoLoads()->count(),
            'membership' => $user->membership()->exists(),
            'created_matches' => $user->createdMatches()->count(),
        ];

        return view('user-management.confirm-delete', compact('user', 'impact'));
    }

    public function forceDelete(Request $request, int $id): RedirectResponse
    {
        $actor = $request->user();
        $user = User::onlyTrashed()->findOrFail($id);

        $request->validate([
            'confirm_email' => ['required', 'string', 'in:' . $user->email],
        ], [
            'confirm_email.in' => 'The email address does not match. Permanent deletion cancelled.',
        ]);

        $name = $user->name;
        $email = $user->email;

        $this->auditLogService->log(
            $actor,
            'user.permanently_deleted',
            'User',
            $user->id,
            ['name' => $name, 'email' => $email],
            null,
            "Permanently deleted user {$name} ({$email}) and all related data",
        );

        $user->scores()->delete();
        $user->matchRegistrations()->delete();
        $user->rifleConfigurations()->each(function ($rifle) {
            $rifle->ammoLoads()->delete();
            $rifle->forceDelete();
        });
        $user->ammoLoads()->delete();
        $user->membership()?->delete();
        $user->committeePositions()->delete();

        $user->forceDelete();

        return redirect()->route('user-management.index', ['trashed' => 1])
            ->with('success', "{$name} ({$email}) has been permanently deleted along with all related data.");
    }
}
