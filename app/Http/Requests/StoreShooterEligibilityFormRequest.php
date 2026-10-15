<?php

namespace App\Http\Requests;

use App\Models\SelectionCycle;
use App\Services\Selection\DeclarationAttestations;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shooter's combined intention-to-participate (DEC-01) + Eligibility-to-Compete
 * attestation, submitted from `/iprf`. Every attestation the cycle asks for
 * (see DeclarationAttestations) must be ticked — the policy makes each one a
 * precondition for selection — and the signature must match the authenticated
 * user's account name so we have a per-submission audit trail that ties the
 * declaration back to the person on record.
 */
class StoreShooterEligibilityFormRequest extends FormRequest
{
    /** @var array<string, string> */
    private const DEFAULT_MESSAGES = [
        'intention_to_participate' => 'You must declare your intention to participate.',
        'able_and_willing' => 'You must confirm you are able and willing to undertake the selection programme.',
        'satisfy_preconditions' => 'You must agree to satisfy any preconditions advised by ExCo.',
        'no_impairment' => 'You must confirm you are not suffering an impairment that would prevent you from competing.',
    ];

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $rules = [];
        foreach (array_keys($this->attestations()) as $key) {
            $rules[$key] = ['accepted'];
        }

        return $rules + [
            'signature' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        $messages = [];
        foreach ($this->attestations() as $key => $text) {
            $messages[$key.'.accepted'] = self::DEFAULT_MESSAGES[$key] ?? 'You must agree to: '.$text;
        }

        return $messages + [
            'signature.required' => 'Please type your full name as your signature.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attestations(): array
    {
        $cycle = $this->route('cycle');

        return $cycle instanceof SelectionCycle
            ? DeclarationAttestations::forCycle($cycle)
            : DeclarationAttestations::DEFAULTS;
    }
}
