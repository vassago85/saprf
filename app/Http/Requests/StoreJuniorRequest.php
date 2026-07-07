<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates creation of a managed family account (junior, spouse, parent, …).
 * Date of birth is only required for juniors (needed for age-category); adults
 * may leave it blank.
 */
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
            'relationship' => ['required', Rule::in(array_keys(User::MANAGED_RELATIONSHIPS))],
            'date_of_birth' => [
                Rule::requiredIf(fn () => $this->input('relationship') === 'junior'),
                'nullable',
                'date',
                'before:today',
            ],
            'province_id' => ['required', 'exists:provinces,id'],
            'division_id' => ['required', 'exists:divisions,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'relationship.required' => 'Choose how this person is related to you.',
            'relationship.in' => 'Please pick a valid relationship.',
            'date_of_birth.required' => 'Date of birth is required for junior accounts.',
            'date_of_birth.before' => 'Date of birth must be in the past.',
        ];
    }
}
