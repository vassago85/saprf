<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Staff typeahead for picking a person. Matches name or SAPRF number and
 * returns "Name (number)" so callers do not have to remember the number.
 */
class MemberLookupController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));

        if (mb_strlen($term) < 2) {
            return response()->json(['results' => []]);
        }

        $like = '%'.$term.'%';

        $users = User::query()
            ->select('users.id', 'users.name')
            ->leftJoin('memberships', 'memberships.user_id', '=', 'users.id')
            ->addSelect('memberships.saprf_number as saprf_number')
            ->where('users.is_active', true)
            ->where(function ($q) use ($like, $term): void {
                $q->where('users.name', 'like', $like)
                    ->orWhere('memberships.saprf_number', 'like', $term.'%');
            })
            ->orderBy('users.name')
            ->limit(15)
            ->get();

        return response()->json([
            'results' => $users->map(function ($user) {
                $number = $user->saprf_number;

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'saprf_number' => $number,
                    'label' => $number ? $user->name.' ('.$number.')' : $user->name,
                ];
            })->values(),
        ]);
    }
}
