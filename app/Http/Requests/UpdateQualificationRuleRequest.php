<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateQualificationRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('qualification_rule'));
    }

    public function rules(): array
    {
        return [
            'series' => ['required', Rule::in(['PRS', 'PR22'])],
            'season' => ['required', 'string', 'max:10'],
            'min_out_of_province_matches' => ['required', 'integer', 'min:0', 'max:20'],
            'best_of_count' => ['nullable', 'integer', 'min:1', 'max:20'],
            'total_qualifying_matches' => ['nullable', 'integer', 'min:1', 'max:30'],
            'weighted_final_enabled' => ['boolean'],
            'weighted_final_multiplier' => ['nullable', 'numeric', 'min:1', 'max:5'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'weighted_final_enabled' => $this->boolean('weighted_final_enabled'),
        ]);
    }
}
