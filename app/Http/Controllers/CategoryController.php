<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CategoryController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    public function index(): View
    {
        $categories = Category::ordered()->get();

        return view('categories.index', compact('categories'));
    }

    public function create(): View
    {
        return view('categories.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'alpha_dash', 'max:30', 'unique:categories,code'],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'is_age_based' => ['sometimes', 'boolean'],
            'min_age' => ['nullable', 'integer', 'min:0', 'max:120', 'required_if:is_age_based,1'],
            'max_age' => ['nullable', 'integer', 'min:0', 'max:120'],
            'display_order' => ['required', 'integer', 'min:0'],
        ]);

        $validated['is_age_based'] = $request->boolean('is_age_based');

        if (! $validated['is_age_based']) {
            $validated['min_age'] = null;
            $validated['max_age'] = null;
        }

        $category = Category::create($validated);

        $this->auditLogService->log(
            $request->user(),
            'category_created',
            'Category',
            $category->id,
            null,
            ['code' => $category->code, 'name' => $category->name],
            "Category '{$category->name}' created",
        );

        return redirect()->route('categories.index')->with('success', "Category '{$category->name}' created.");
    }

    public function edit(Category $category): View
    {
        return view('categories.edit', compact('category'));
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'alpha_dash', 'max:30', 'unique:categories,code,' . $category->id],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'is_age_based' => ['sometimes', 'boolean'],
            'min_age' => ['nullable', 'integer', 'min:0', 'max:120', 'required_if:is_age_based,1'],
            'max_age' => ['nullable', 'integer', 'min:0', 'max:120'],
            'display_order' => ['required', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $old = $category->only(['code', 'name', 'is_age_based', 'is_active']);
        $validated['is_age_based'] = $request->boolean('is_age_based');
        $validated['is_active'] = $request->boolean('is_active', true);

        if (! $validated['is_age_based']) {
            $validated['min_age'] = null;
            $validated['max_age'] = null;
        }

        $category->update($validated);

        $this->auditLogService->log(
            $request->user(),
            'category_updated',
            'Category',
            $category->id,
            $old,
            $category->only(['code', 'name', 'is_age_based', 'is_active']),
            "Category '{$category->name}' updated",
        );

        return redirect()->route('categories.index')->with('success', "Category '{$category->name}' updated.");
    }
}
