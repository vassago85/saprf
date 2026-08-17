<?php

namespace App\Http\Controllers;

use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Powers the "Enter or pay for another member" search on the match
 * registration page. Deliberately kept narrow: only the fields a sponsor
 * needs to identify the right person (name, SAPRF number, province) and
 * the shooter's entry state on this match. No emails, phones, or IDs
 * leak from here.
 */
class MemberSearchController extends Controller
{
    public function search(Request $request, MatchEvent $match): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor, 401);

        $term = trim((string) $request->input('q', ''));

        // A tiny query would drown the caller in false positives. Force at
        // least two characters so the endpoint can never return the entire
        // membership roster in a single hit.
        if (mb_strlen($term) < 2) {
            return response()->json(['results' => []]);
        }

        $like = '%' . $term . '%';

        $users = User::query()
            ->select('users.id', 'users.name', 'users.province_id')
            ->leftJoin('memberships', 'memberships.user_id', '=', 'users.id')
            ->addSelect('memberships.saprf_number as saprf_number')
            ->where('users.is_active', true)
            ->where('users.id', '!=', $actor->id)
            // Managed family accounts (juniors etc.) are entered via the
            // family dropdown, not the sponsor search — hide them here.
            ->where(function ($q): void {
                $q->whereNull('users.is_managed_account')
                    ->orWhere('users.is_managed_account', false);
            })
            ->where(function ($q) use ($like, $term): void {
                $q->where('users.name', 'like', $like)
                    ->orWhere('memberships.saprf_number', 'like', $term . '%');
            })
            ->with('province:id,name')
            ->orderBy('users.name')
            ->limit(10)
            ->get();

        // Look up each candidate's current entry on this match in a single
        // query rather than N+1 lookups inside the map.
        $existingEntries = MatchRegistration::query()
            ->where('match_id', $match->id)
            ->whereIn('user_id', $users->pluck('id'))
            ->get()
            ->keyBy('user_id');

        $results = $users->map(function (User $user) use ($existingEntries) {
            $entry = $existingEntries->get($user->id);

            return [
                'id' => $user->id,
                'name' => $user->name,
                'saprf_number' => $user->saprf_number,
                'province' => $user->province?->name,
                'entry_state' => $this->entryState($entry),
                'registration_id' => $entry?->id,
            ];
        })->values();

        return response()->json(['results' => $results]);
    }

    /**
     * Compact status a sponsor cares about: can they enter this shooter,
     * pay their unpaid entry, or is there nothing to do?
     */
    private function entryState(?MatchRegistration $entry): string
    {
        if (! $entry) {
            return 'none';
        }

        if ($entry->registration_status === 'cancelled') {
            return 'cancelled';
        }

        return match ($entry->payment_status) {
            'paid' => 'paid',
            default => 'unpaid',
        };
    }
}
