<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('match'));
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'match_type' => ['required', Rule::in(['PRS', 'PR22'])],
            'series_level' => ['nullable', Rule::in(['national', 'provincial', 'club', 'final'])],
            'province_id' => ['nullable', 'exists:provinces,id'],
            'match_date' => ['nullable', 'date'],
            'match_end_date' => ['nullable', 'date', 'after_or_equal:match_date'],
            'venue_name' => ['nullable', 'string', 'max:255'],
            'venue_location' => ['nullable', 'string'],
            'city' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'registration_open_date' => ['nullable', 'date'],
            'registration_close_date' => ['nullable', 'date', 'after_or_equal:registration_open_date'],
            'active_member_fee' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', Rule::in(['draft', 'open', 'closed', 'completed', 'cancelled'])],
            'max_competitors' => ['nullable', 'integer', 'min:1', 'max:999'],
            'waitlist_enabled' => ['boolean'],
            'divisions' => ['nullable', 'array'],
            'divisions.*' => ['exists:divisions,id'],
            'category_rankings_enabled' => ['boolean'],
            'division_awards_enabled' => ['boolean'],
            'category_awards_enabled' => ['boolean'],
            'estimated_shooters' => ['nullable', 'integer', 'min:1', 'max:999'],
            'also_counts_for_provincial' => ['boolean'],
            'provincial_stage_columns' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
