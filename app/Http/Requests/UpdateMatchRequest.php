<?php

namespace App\Http\Requests;

use App\Models\MatchEvent;
use App\Services\ImpactScoring\ImpactScoringClient;
use App\Support\MatchName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use RuntimeException;

class UpdateMatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('match'));
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => MatchName::normalize($this->input('name'))]);
        }

        // Phase 3.3: the admin form posts the three JSON blobs as raw strings
        // in a textarea. Decode once here so the rules below can validate
        // their shape (array of {time,label} / etc.) rather than string
        // contents. Empty / whitespace / non-JSON leaves the field as null
        // so the UI can tell "no card" apart from "card with 0 rows".
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
            // If decoding failed, leave the string in place so the rules
            // below reject it with a readable "must be valid JSON" error.
        }
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
            // Phase 3.4: default-off toggle for the MD phone's public visibility.
            'show_md_contact_publicly' => ['boolean'],
            // Phase 3.3: optional match-day detail fields.
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
            'remove_banner' => ['sometimes', 'boolean'],
            'poster' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:6144'],
            'remove_poster' => ['sometimes', 'boolean'],
            'registration_open_date' => ['nullable', 'date'],
            'registration_close_date' => ['nullable', 'date', 'after_or_equal:registration_open_date'],
            'active_member_fee' => ['nullable', 'numeric', 'min:0'],
            'junior_fee' => ['nullable', 'numeric', 'min:0'],
            'club_entry_fee' => ['nullable', 'numeric', 'min:0'],
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
            'impact_live_sync' => ['boolean'],
            'impact_ref' => [
                'nullable',
                'string',
                'max:500',
                Rule::requiredIf(fn () => $this->boolean('impact_live_sync')),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (blank($value)) {
                        return;
                    }

                    try {
                        app(ImpactScoringClient::class)->parseMatchId((string) $value);
                    } catch (RuntimeException) {
                        $fail('Paste the Impact match link (/match/view?id=…) or the match number.');
                    }
                },
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'whatsapp_invite_url.regex' => 'The invite must be a WhatsApp group link starting with https://chat.whatsapp.com/',
            'impact_ref.required' => 'Paste the Impact match link to turn live scores on.',
        ];
    }
}
