<?php

namespace App\Http\Requests;

use App\Models\Score;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            // For 2-day matches, MDs tag their CSV upload as either day 1 or day 2.
            // The importer then merges rows into a single score-per-shooter with
            // day1_raw_score and day2_raw_score populated correctly.
            'day' => ['nullable', 'integer', Rule::in([1, 2])],
        ];
    }

    public function messages(): array
    {
        return [
            'file.mimes' => 'Please upload a CSV file (export from Excel using "Save As → CSV UTF-8").',
            'file.max' => 'File is too large. Maximum is 20 MB — split large matches across multiple imports if needed.',
        ];
    }
}
