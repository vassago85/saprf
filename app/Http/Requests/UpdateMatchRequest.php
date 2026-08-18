<?php

namespace App\Http\Requests;

use App\Models\MatchEvent;
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
            'series_level' => ['nullable', Rule::in(['national', 'provincial', 'club', 'final', 'international'])],
            'province_id' => ['nullable', 'exists:provinces,id'],
            'match_date' => ['nullable', 'date'],
            'match_end_date' => ['nullable', 'date', 'after_or_equal:match_date'],
            'venue_name' => ['nullable', 'string', 'max:255'],
            'venue_location' => ['nullable', 'string'],
            'city' => ['nullable', 'string', 'max:255'],
            'match_director' => ['nullable', 'string', 'max:255'],
            'match_director_contact' => ['nullable', 'string', 'max:255'],
            'whatsapp_invite_url' => MatchEvent::whatsappInviteUrlRules(),
            'description' => ['nullable', 'string'],
            'registration_open_date' => ['nullable', 'date'],
            'registration_close_date' => ['nullable', 'date', 'after_or_equal:registration_open_date'],
            'active_member_fee' => ['nullable', 'numeric', 'min:0'],
            'junior_fee' => ['nullable', 'numeric', 'min:0'],
            // Fee overrides: must come as a matched pair (either both set or
            // both blank) so we never mix a match-level type with a global
            // value. Only exco/developer are allowed to submit these — the
            // controller silently drops them for anyone else.
            'platform_fee_type' => ['nullable', 'in:fixed,percentage', 'required_with:platform_fee_value'],
            'platform_fee_value' => ['nullable', 'numeric', 'min:0', 'max:99999.99', 'required_with:platform_fee_type'],
            'saprf_fee_type' => ['nullable', 'in:fixed,percentage', 'required_with:saprf_fee_value'],
            'saprf_fee_value' => ['nullable', 'numeric', 'min:0', 'max:99999.99', 'required_with:saprf_fee_type'],
            'status' => ['nullable', Rule::in(['draft', 'open', 'closed', 'completed', 'cancelled'])],
            'max_competitors' => ['nullable', 'integer', 'min:1', 'max:999'],
            'waitlist_enabled' => ['boolean'],
            'divisions' => ['nullable', 'array'],
            'divisions.*' => ['exists:divisions,id'],
            'estimated_shooters' => ['nullable', 'integer', 'min:1', 'max:999'],
            'also_counts_for_provincial' => ['boolean'],
            'everyone_counts' => ['boolean'],
            'provincial_stage_columns' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'whatsapp_invite_url.regex' => 'The invite must be a WhatsApp group link starting with https://chat.whatsapp.com/',
        ];
    }
}
