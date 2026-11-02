<?php

namespace App\Http\Requests;

use App\Models\MatchEvent;
use App\Support\MatchName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', MatchEvent::class);
    }

    protected function prepareForValidation(): void
    {
        // Normalise spellings on the way in so a match director typing
        // "CenterFire 1day" is stored as "Centrefire 1-Day" — one spelling
        // across the whole site, enforced at the write path rather than fixed
        // afterwards.
        if ($this->has('name')) {
            $this->merge(['name' => MatchName::normalize($this->input('name'))]);
        }

        // Phase 3.3: same JSON decode treatment as UpdateMatchRequest — see
        // the comment there for why we decode in prepareForValidation.
        foreach (['schedule_json', 'squadding_json', 'attachments_json'] as $key) {
            if (! $this->has($key)) {
                continue;
            }
            $raw = $this->input($key);
            if (is_array($raw) || $raw === null) {
                continue;
            }
            $trimmed = trim((string) $raw);
            if ($trimmed === '') {
                $this->merge([$key => null]);

                continue;
            }
            $decoded = json_decode($trimmed, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $this->merge([$key => $decoded]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'match_type' => ['required', Rule::in(['PRS', 'PR22'])],
            'series_level' => ['required', Rule::in(['national', 'provincial', 'club', 'final', 'international'])],
            'province_id' => ['nullable', 'exists:provinces,id'],
            'match_date' => ['required', 'date', 'after_or_equal:today'],
            'match_end_date' => ['nullable', 'date', 'after_or_equal:match_date'],
            'venue_name' => ['nullable', 'string', 'max:255'],
            'venue_location' => ['nullable', 'string'],
            'city' => ['nullable', 'string', 'max:255'],
            'match_director' => ['nullable', 'string', 'max:255'],
            'match_director_contact' => ['nullable', 'string', 'max:255'],
            // Phase 3.4.
            'show_md_contact_publicly' => ['boolean'],
            // Phase 3.3.
            'venue_details' => ['nullable', 'string', 'max:5000'],
            'schedule_json' => ['nullable', 'array', 'max:50'],
            'schedule_json.*.time' => ['nullable', 'string', 'max:40'],
            'schedule_json.*.label' => ['nullable', 'string', 'max:200'],
            'squadding_json' => ['nullable', 'array', 'max:50'],
            'squadding_json.*.squad_name' => ['nullable', 'string', 'max:80'],
            'squadding_json.*.members' => ['nullable', 'array', 'max:40'],
            'squadding_json.*.members.*' => ['nullable', 'string', 'max:120'],
            'attachments_json' => ['nullable', 'array', 'max:20'],
            'attachments_json.*.label' => ['nullable', 'string', 'max:120'],
            'attachments_json.*.url' => ['required_with:attachments_json.*.label', 'nullable', 'url', 'max:500'],
            'whatsapp_invite_url' => MatchEvent::whatsappInviteUrlRules(),
            'description' => ['nullable', 'string'],
            'banner' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'poster' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:6144'],
            'registration_open_date' => ['nullable', 'date'],
            'registration_close_date' => ['nullable', 'date', 'after_or_equal:registration_open_date'],
            'active_member_fee' => ['required', 'numeric', 'min:0'],
            'junior_fee' => ['nullable', 'numeric', 'min:0'],
            'club_entry_fee' => ['nullable', 'numeric', 'min:0'],
            'club_entry_enabled' => ['boolean'],
            'status' => ['nullable', Rule::in(['draft', 'open', 'closed'])],
            'max_competitors' => ['nullable', 'integer', 'min:1', 'max:999'],
            'waitlist_enabled' => ['boolean'],
            'divisions' => ['nullable', 'array'],
            'divisions.*' => ['exists:divisions,id'],
            'estimated_shooters' => ['nullable', 'integer', 'min:1', 'max:999'],
            'also_counts_for_provincial' => ['boolean'],
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
