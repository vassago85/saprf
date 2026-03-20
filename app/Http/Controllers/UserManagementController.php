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
        $query = User::with('roles');

        $search = $request->input('search');
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $users = $query->orderBy('name')->paginate(20)->withQueryString();

        return view('user-management.index', compact('users', 'search'));
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

        if ($user->id === $actor->id) {
            return back()->with('error', 'You cannot change your own roles.');
        }

        if ($user->hasRole('owner')) {
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
}
