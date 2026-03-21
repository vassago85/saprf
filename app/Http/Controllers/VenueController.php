<?php

namespace App\Http\Controllers;

use App\Models\Province;
use App\Models\Venue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class VenueController extends Controller
{
    public function index(Request $request): View
    {
        $search = $request->input('search');
        $provinceFilter = $request->input('province_id');

        $venues = Venue::with('province')
            ->search($search)
            ->when($provinceFilter, fn ($q) => $q->where('province_id', $provinceFilter))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        $provinces = Province::orderBy('name')->get();

        return view('venues.index', compact('venues', 'provinces', 'search', 'provinceFilter'));
    }

    public function create(): View
    {
        $provinces = Province::orderBy('name')->get();

        return view('venues.create', compact('provinces'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->rules());

        $venue = Venue::create($validated);

        return redirect()->route('venues.index')
            ->with('success', "Venue '{$venue->name}' created.");
    }

    public function edit(Venue $venue): View
    {
        $provinces = Province::orderBy('name')->get();

        return view('venues.edit', compact('venue', 'provinces'));
    }

    public function update(Request $request, Venue $venue): RedirectResponse
    {
        $validated = $request->validate($this->rules());

        $venue->update($validated);

        return redirect()->route('venues.index')
            ->with('success', "Venue '{$venue->name}' updated.");
    }

    public function destroy(Venue $venue): RedirectResponse
    {
        $name = $venue->name;
        $venue->delete();

        return redirect()->route('venues.index')
            ->with('success', "Venue '{$name}' deleted.");
    }

    public function search(Request $request): JsonResponse
    {
        $term = $request->input('q', '');

        $venues = Venue::active()
            ->search($term)
            ->with('province')
            ->orderBy('name')
            ->limit(15)
            ->get()
            ->map(fn (Venue $v) => [
                'id' => $v->id,
                'name' => $v->name,
                'city' => $v->city,
                'province' => $v->province?->name,
                'address' => $v->fullAddress(),
            ]);

        return response()->json($venues);
    }

    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'province_id' => ['nullable', 'exists:provinces,id'],
            'postal_code' => ['nullable', 'string', 'max:10'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
