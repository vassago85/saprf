<?php

namespace App\Services\Selection;

use App\Models\SelectionCycle;

/**
 * The tick-box commitments a shooter signs on the online Eligibility to
 * Compete / declaration form at /iprf.
 *
 * Each cycle can supply its own list in its policy JSON:
 *
 *     "declaration": {"attestations": [{"key": "abide_by_team_captain", "text": "..."}]}
 *
 * Cycles whose policy has no such list get DEFAULTS, which is the form the
 * PRS 2026 and PR22 2027 cycles were opened with. `:championship` in any text
 * is replaced with the cycle's championship name.
 *
 * FOR_2028_NATIONAL_TEAM is the nine declarations on the eligibility form
 * in the 19 October 2026 selection process. A 2028 cycle's policy JSON
 * should copy that list into declaration.attestations. DEFAULTS stay as
 * they are so the earlier cycles do not silently change what was signed.
 */
class DeclarationAttestations
{
    /** @var array<string, string> */
    public const DEFAULTS = [
        'intention_to_participate' => 'I declare my intention to participate in the :championship.',
        'able_and_willing' => 'I am able and willing to undertake the matches, training and competition programme recommended by ExCo and the Selectors, unless a written exemption is granted.',
        'satisfy_preconditions' => 'I will satisfy any additional preconditions advised in writing by ExCo prior to or at the time of selection.',
        'no_impairment' => 'I confirm that I am not suffering any physical or mental impairment that may prevent me from competing at the highest possible standard.',
    ];

    /**
     * Signed eligibility declarations, 19 October 2026.
     * Replaces the eight shorter bullets in the 14 October series draft
     * (team captain became Team Manager, finance and attire are separate,
     * and the selectors' decision is final with no further discussion).
     *
     * @var array<string, string>
     */
    public const FOR_2028_NATIONAL_TEAM = [
        'attend_if_selected' => 'In the event of my being selected, I will ensure that I will be able to attend the International Precision Rifle World Championships.',
        'report_unavailability' => 'In the event of ill health of myself, or any other circumstance that might prevent me from attending the Championships, I undertake to inform ExCo immediately.',
        'available_for_training' => 'I will make myself available to attend any training, team building and/or competitions to be held prior to the Championship.',
        'sportsmanship' => 'I agree to actively participate in a manner which reflects true sportsmanship and help build team spirit.',
        'financial_commitment' => 'I am aware of the financial implications of attending the championships and I am in a position to finance my attendance at this championship.',
        'national_colours' => 'In the event of my obtaining National Colours, I agree to wear these colours proudly and always put the needs of my Nation and my Team ahead of my own.',
        'purchase_attire' => 'I agree to purchase any required attire.',
        'selectors_final' => 'I accept that the decision of the selectors is final and that no further discussions will be entered into.',
        'abide_by_team_manager' => 'I agree to abide by the decisions made by the Team Manager.',
    ];

    /** @var array<string, string> */
    private const LEGACY_SHORT_LABELS = [
        'intention_to_participate' => 'Intention to participate',
        'able_and_willing' => 'Able and willing to undertake the programme',
        'satisfy_preconditions' => 'Will satisfy ExCo preconditions',
        'no_impairment' => 'No impairment',
    ];

    /**
     * @return array<string, string> key => text, in display order
     */
    public static function forCycle(SelectionCycle $cycle): array
    {
        $configured = $cycle->activePolicy?->spec_json['declaration']['attestations'] ?? [];

        $items = [];
        foreach (is_array($configured) ? $configured : [] as $item) {
            $key = $item['key'] ?? null;
            $text = $item['text'] ?? null;
            if (is_string($key) && preg_match('/^[a-z][a-z0-9_]*$/', $key) && is_string($text) && $text !== '') {
                $items[$key] = $text;
            }
        }

        if ($items === []) {
            $items = self::DEFAULTS;
        }

        $championship = $cycle->championship_name ?: trim($cycle->series.' '.$cycle->season);

        return array_map(fn (string $text) => str_replace(':championship', $championship, $text), $items);
    }

    /**
     * Label for one stored attestation on the admin athlete page. Prefers the
     * text the shooter actually signed, which is stored with the declaration.
     *
     * @param  array<string, mixed>  $formData
     */
    public static function label(array $formData, string $key): string
    {
        return $formData['attestation_texts'][$key]
            ?? self::LEGACY_SHORT_LABELS[$key]
            ?? ucfirst(str_replace('_', ' ', $key));
    }
}
