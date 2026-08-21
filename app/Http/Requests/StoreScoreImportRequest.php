<?php

namespace App\Http\Requests;

use App\Models\MatchEvent;
use App\Models\Score;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreScoreImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Score::class);
    }

    public function rules(): array
    {
        return [
            'match_id' => ['required', 'exists:matches,id'],
            'source_type' => ['required', Rule::in(['csv', 'practiscore', 'impact', 'manual', 'other'])],
            // Allow common CSV extensions (some spreadsheet apps export as text/plain).
            // Cap at 20 MB to comfortably accommodate large matches.
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:20480'],
            'replace_existing' => ['nullable', 'boolean'],
            // For 2-day nationals: day1 → sibling provincial; overall → national totals.
            // Legacy day 1/2 still accepted for older clients but is not shown in the UI.
            'score_scope' => ['nullable', Rule::in(['day1', 'overall'])],
            'day' => ['nullable', 'integer', Rule::in([1, 2])],
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

            if (! $this->filled('score_scope') && ! $this->filled('day')) {
                $validator->errors()->add(
                    'score_scope',
                    'Choose whether this CSV is Day 1 scores or Overall scores.'
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'file.mimes' => 'Please upload a CSV file (export from Excel using "Save As → CSV UTF-8").',
            'file.max' => 'File is too large. Maximum is 20 MB — split large matches across multiple imports if needed.',
            'score_scope.in' => 'Score scope must be Day 1 or Overall.',
        ];
    }
}
