<?php

namespace App\Http\Requests;

use App\Models\MatchEvent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeMatchDirectorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('changeDirector', $this->route('match'));
    }

    public function rules(): array
    {
        /** @var MatchEvent $match */
        $match = $this->route('match');

        return [
            'user_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(fn ($q) => $q->where('is_active', true)),
                // Selecting the current director would be a no-op — reject
                // it so the UI can't silently "succeed" without change.
                Rule::notIn([$match->created_by]),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.not_in' => 'This user is already the match director.',
            'user_id.exists' => 'Selected user is not an active platform user.',
        ];
    }
}
