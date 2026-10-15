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
