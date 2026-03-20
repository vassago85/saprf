<?php

namespace App\Http\Requests;

use App\Models\QualificationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreQualificationRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', QualificationRule::class);
    }

    public function rules(): array
    {
        return [
            'series' => ['required', Rule::in(['PRS', 'PR22'])],
            'season' => ['required', 'string', 'max:10'],
            'min_out_of_province_matches' => ['required', 'integer', 'min:0', 'max:20'],
        ];
    }
}
