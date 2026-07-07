<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreJuniorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'date_of_birth' => ['required', 'date', 'before:today', 'after:1995-01-01'],
            'province_id' => ['required', 'exists:provinces,id'],
            'division_id' => ['required', 'exists:divisions,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'date_of_birth.after' => 'Junior accounts are for shooters born after 1 January 1995. For older shooters please register them with their own email.',
            'date_of_birth.before' => 'Date of birth must be in the past.',
        ];
    }
}
