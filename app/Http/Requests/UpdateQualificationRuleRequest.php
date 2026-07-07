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
        $isPooled = $this->input('scoring_mode') === 'weighted_pools';

        return [
            'series' => ['required', Rule::in(['PRS', 'PR22'])],
            'season' => ['required', 'string', 'max:10'],
            'scoring_mode' => ['nullable', Rule::in(['best_of_n', 'weighted_pools'])],
            'min_out_of_province_matches' => ['required', 'integer', 'min:0', 'max:20'],
            'best_of_count' => ['nullable', 'integer', 'min:1', 'max:20'],
            'total_qualifying_matches' => ['nullable', 'integer', 'min:1', 'max:30'],
            'weighted_final_enabled' => ['boolean'],
            'weighted_final_multiplier' => ['nullable', 'numeric', 'min:1', 'max:5'],
            'provincial_pool_best_of' => [$isPooled ? 'required' : 'nullable', 'integer', 'min:1', 'max:20'],
            'provincial_pool_weight_pct' => [$isPooled ? 'required' : 'nullable', 'numeric', 'min:0', 'max:100'],
            'national_pool_best_of' => [$isPooled ? 'required' : 'nullable', 'integer', 'min:1', 'max:20'],
            'national_pool_weight_pct' => [$isPooled ? 'required' : 'nullable', 'numeric', 'min:0', 'max:100'],
            'champs_pool_best_of' => [$isPooled ? 'required' : 'nullable', 'integer', 'min:1', 'max:20'],
            'champs_pool_weight_pct' => [$isPooled ? 'required' : 'nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v): void {
            if ($this->input('scoring_mode') !== 'weighted_pools') {
                return;
            }
            $sum = (float) $this->input('provincial_pool_weight_pct', 0)
                + (float) $this->input('national_pool_weight_pct', 0)
                + (float) $this->input('champs_pool_weight_pct', 0);
            if (abs($sum - 100.0) > 0.01) {
                $v->errors()->add('provincial_pool_weight_pct',
                    'Pool weights must add up to 100%. Current total: ' . number_format($sum, 2) . '%.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'weighted_final_enabled' => $this->boolean('weighted_final_enabled'),
            'scoring_mode' => $this->input('scoring_mode', 'best_of_n'),
        ]);
    }
}
