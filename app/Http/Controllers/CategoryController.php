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
            'slug' => ['required', 'string', 'alpha_dash', 'max:30', 'unique:categories,slug'],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'display_order' => ['required', 'integer', 'min:0'],
        ]);

        $category = Category::create($validated);

        $this->auditLogService->log(
            $request->user(),
            'category_created',
            'Category',
            $category->id,
            null,
            ['slug' => $category->slug, 'name' => $category->name],
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
            'slug' => ['required', 'string', 'alpha_dash', 'max:30', 'unique:categories,slug,' . $category->id],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'display_order' => ['required', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $old = $category->only(['slug', 'name', 'is_active']);
        $validated['is_active'] = $request->boolean('is_active', true);

        $category->update($validated);

        $this->auditLogService->log(
            $request->user(),
            'category_updated',
            'Category',
            $category->id,
            $old,
            $category->only(['slug', 'name', 'is_active']),
            "Category '{$category->name}' updated",
        );

        return redirect()->route('categories.index')->with('success', "Category '{$category->name}' updated.");
    }
}
