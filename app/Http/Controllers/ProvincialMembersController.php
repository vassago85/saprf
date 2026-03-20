<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProvincialMembersController extends Controller
{
    public function index(Request $request): View
    {
        $actor = $request->user();
        $provinceIds = $actor->getAdminProvinceIds();

        if ($actor->hasRole(['owner', 'admin'])) {
            $provinceIds = null;
        }

        $query = User::with(['province', 'membership', 'roles'])
            ->whereHas('membership');

        if ($provinceIds !== null) {
            $query->whereIn('province_id', $provinceIds);
        }

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhereHas('membership', fn ($mq) => $mq->where('saprf_number', 'like', "%{$search}%"));
            });
        }

        if ($provinceFilter = $request->input('province_id')) {
            $query->where('province_id', $provinceFilter);
        }

        $users = $query->orderBy('name')->paginate(25)->withQueryString();
        $provinces = \App\Models\Province::orderBy('name')->get();
        $showSaId = $actor->hasRole(['owner', 'admin']);

        return view('provincial-members.index', compact('users', 'provinces', 'search', 'showSaId'));
    }
}
