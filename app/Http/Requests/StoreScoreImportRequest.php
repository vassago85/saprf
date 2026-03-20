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
            'source_type' => ['required', Rule::in(['practiscore', 'impact', 'manual', 'other'])],
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ];
    }
}
