<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreQualificationRuleRequest;
use App\Http\Requests\UpdateQualificationRuleRequest;
use App\Models\QualificationRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class QualificationRuleController extends Controller
{
    public function index(Request $request): View
    {
        $rules = QualificationRule::query()
            ->with('creator')
            ->latest()
            ->paginate(20);

        return view('qualification-rules.index', compact('rules'));
    }

    public function create(): View
    {
        return view('qualification-rules.create');
    }

    public function store(StoreQualificationRuleRequest $request): RedirectResponse
    {
        QualificationRule::query()->create([
            ...$request->validated(),
            'created_by' => $request->user()->id,
        ]);

        return redirect()->route('qualification-rules.index')
            ->with('success', 'Qualification rule created.');
    }

    public function edit(QualificationRule $qualificationRule): View
    {
        return view('qualification-rules.edit', compact('qualificationRule'));
    }

    public function update(UpdateQualificationRuleRequest $request, QualificationRule $qualificationRule): RedirectResponse
    {
        $qualificationRule->update($request->validated());

        return redirect()->route('qualification-rules.index')
            ->with('success', 'Qualification rule updated.');
    }
}
