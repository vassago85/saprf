<?php

namespace App\Http\Controllers;

use App\Models\Division;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DivisionController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    public function index(): View
    {
        $divisions = Division::ordered()->get();

        return view('divisions.index', compact('divisions'));
    }

    public function create(): View
    {
        return view('divisions.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'slug' => ['required', 'string', 'alpha_dash', 'max:30', 'unique:divisions,slug'],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'display_order' => ['required', 'integer', 'min:0'],
        ]);

        $division = Division::create($validated);

        $this->auditLogService->log(
            $request->user(),
            'division_created',
            'Division',
            $division->id,
            null,
            ['slug' => $division->slug, 'name' => $division->name],
            "Division '{$division->name}' created",
        );

        return redirect()->route('divisions.index')->with('success', "Division '{$division->name}' created.");
    }

    public function edit(Division $division): View
    {
        return view('divisions.edit', compact('division'));
    }

    public function update(Request $request, Division $division): RedirectResponse
    {
        $validated = $request->validate([
            'slug' => ['required', 'string', 'alpha_dash', 'max:30', 'unique:divisions,slug,' . $division->id],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'display_order' => ['required', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $old = $division->only(['slug', 'name', 'is_active']);
        $validated['is_active'] = $request->boolean('is_active', true);

        $division->update($validated);

        $this->auditLogService->log(
            $request->user(),
            'division_updated',
            'Division',
            $division->id,
            $old,
            $division->only(['slug', 'name', 'is_active']),
            "Division '{$division->name}' updated",
        );

        return redirect()->route('divisions.index')->with('success', "Division '{$division->name}' updated.");
    }
}
