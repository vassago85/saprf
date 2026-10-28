<?php

namespace App\Http\Requests;

use App\Models\MatchEvent;
use App\Models\Score;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreImpactLiveImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Score::class);
    }

    public function rules(): array
    {
        return [
            'match_id' => ['required', 'exists:matches,id'],
            'impact_ref' => ['required', 'string', 'max:500'],
            'score_scope' => ['nullable', Rule::in(['day1', 'overall'])],
            'day1_stage_ids' => ['nullable', 'array'],
            'day1_stage_ids.*' => ['integer'],
            'replace_existing' => ['nullable', 'boolean'],
            'links' => ['nullable', 'array'],
            'links.*' => ['nullable', 'string', 'max:64'],
            // Optional per-shooter overrides of Impact totals before import.
            'score_overrides' => ['nullable', 'array'],
            'score_overrides.*.impacts' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'score_overrides.*.day1_impacts' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'score_overrides.*.note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $matchId = $this->input('match_id');
            if (! $matchId) {
                return;
            }

            $match = MatchEvent::query()->find($matchId);
            if (! $match || ! $match->isTwoDayNational()) {
                return;
            }

            if (! $this->filled('score_scope')) {
                $validator->errors()->add(
                    'score_scope',
                    'Choose Day 1 (provincial stages) or Overall for this 2-day national.'
                );
            }

        });
    }
}
