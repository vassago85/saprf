<?php

namespace App\Http\Controllers;

use App\Models\FirearmCalibre;
use App\Models\FirearmMake;
use App\Models\FirearmModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FirearmReferenceController extends Controller
{
    public function searchMakes(Request $request): JsonResponse
    {
        $q = $request->input('q', '');
        $query = FirearmMake::active()->orderBy('name');

        if (strlen($q) >= 1) {
            $query->search($q);
        }

        return response()->json(['data' => $query->limit(30)->get(['id', 'name', 'country'])]);
    }

    public function searchModels(Request $request): JsonResponse
    {
        $query = FirearmModel::active()->orderBy('name');

        if ($makeId = $request->input('make_id')) {
            $query->forMake((int) $makeId);
        }

        if ($q = $request->input('q')) {
            $query->search($q);
        }

        return response()->json(['data' => $query->limit(50)->get(['id', 'firearm_make_id', 'name'])]);
    }

    public function searchCalibres(Request $request): JsonResponse
    {
        $q = $request->input('q', '');
        $query = FirearmCalibre::active()->rifleOrRimfire()->orderBy('name');

        if (strlen($q) >= 1) {
            $query->search($q);
        }

        return response()->json(['data' => $query->limit(30)->get(['id', 'name', 'category', 'family'])]);
    }

    public function storeMake(Request $request): JsonResponse
    {
        $validated = $request->validate(['name' => 'required|string|max:100']);

        $existing = FirearmMake::whereRaw('LOWER(name) = ?', [strtolower($validated['name'])])->first();
        if ($existing) {
            return response()->json(['data' => $existing->only('id', 'name', 'country')]);
        }

        $make = FirearmMake::create([
            'name' => $validated['name'],
            'is_active' => true,
            'user_submitted' => true,
        ]);

        return response()->json(['data' => $make->only('id', 'name', 'country')], 201);
    }

    public function storeModel(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'make_id' => 'required|exists:firearm_makes,id',
        ]);

        $existing = FirearmModel::where('firearm_make_id', $validated['make_id'])
            ->whereRaw('LOWER(name) = ?', [strtolower($validated['name'])])
            ->first();

        if ($existing) {
            return response()->json(['data' => $existing->only('id', 'firearm_make_id', 'name')]);
        }

        $model = FirearmModel::create([
            'firearm_make_id' => $validated['make_id'],
            'name' => $validated['name'],
            'is_active' => true,
            'user_submitted' => true,
        ]);

        return response()->json(['data' => $model->only('id', 'firearm_make_id', 'name')], 201);
    }

    public function storeCalibre(Request $request): JsonResponse
    {
        $validated = $request->validate(['name' => 'required|string|max:100']);

        $existing = FirearmCalibre::whereRaw('LOWER(name) = ?', [strtolower($validated['name'])])->first();
        if ($existing) {
            return response()->json(['data' => $existing->only('id', 'name', 'category', 'family')]);
        }

        $calibre = FirearmCalibre::create([
            'name' => $validated['name'],
            'category' => 'rifle',
            'is_active' => true,
            'user_submitted' => true,
        ]);

        return response()->json(['data' => $calibre->only('id', 'name', 'category', 'family')], 201);
    }
}
