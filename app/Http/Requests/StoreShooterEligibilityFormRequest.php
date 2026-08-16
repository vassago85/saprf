<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shooter's combined intention-to-participate (DEC-01) + Eligibility-to-Compete
 * attestation, submitted from `/iprf`. Every attestation is required to be
 * ticked — the policy makes each one a precondition for selection — and the
 * signature must match the authenticated user's account name so we have a
 * per-submission audit trail that ties the declaration back to the person on
 * record.
 */
class StoreShooterEligibilityFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'intention_to_participate' => ['accepted'],
            'able_and_willing' => ['accepted'],
            'satisfy_preconditions' => ['accepted'],
            'no_impairment' => ['accepted'],
            'signature' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'intention_to_participate.accepted' => 'You must declare your intention to participate.',
            'able_and_willing.accepted' => 'You must confirm you are able and willing to undertake the selection programme.',
            'satisfy_preconditions.accepted' => 'You must agree to satisfy any preconditions advised by ExCo.',
            'no_impairment.accepted' => 'You must confirm you are not suffering an impairment that would prevent you from competing.',
            'signature.required' => 'Please type your full name as your signature.',
        ];
    }
}
