<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UserManagementController extends Controller
{
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
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $users = $query->orderBy('name')->paginate(20)->withQueryString();
        $trashedCount = User::onlyTrashed()->count();

        return view('user-management.index', compact('users', 'search', 'showTrashed', 'trashedCount'));
    }

    public function edit(User $user): View
    {
        $user->load('roles');
        $assignableRoles = ['member', 'match_director', 'admin'];

        return view('user-management.edit', compact('user', 'assignableRoles'));
    }

    public function updateRole(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', 'in:member,match_director,admin'],
        ]);

        $actor = $request->user();

        if ($user->id === $actor->id && !$actor->hasRole('owner')) {
            return back()->with('error', 'You cannot change your own roles.');
        }

        if ($user->hasRole('owner') && $user->id !== $actor->id) {
            return back()->with('error', 'Cannot change the roles of another owner.');
        }

        $oldRoles = $user->getRoleNames()->toArray();
        $newRoles = $validated['roles'];

        if ($user->hasRole('owner')) {
            $newRoles[] = 'owner';
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
        $user->seasonClassifications()->delete();
        $user->categories()->detach();
        $user->committeePositions()->delete();

        $user->forceDelete();

        return redirect()->route('user-management.index', ['trashed' => 1])
            ->with('success', "{$name} ({$email}) has been permanently deleted along with all related data.");
    }
}
