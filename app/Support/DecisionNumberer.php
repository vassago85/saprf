<?php

namespace App\Support;

use App\Models\Decision;
use Illuminate\Support\Collection;

/**
 * Number decisions 1, 2, 3… inside each reference (meeting). Numbers are
 * assigned by database id ascending, so they are stable: publishing a
 * draft later never renumbers existing entries, and a different reference
 * starts again at Decision 1. Decisions with no reference get no number.
 *
 * The helper looks at the whole set of decisions sharing each reference,
 * not just the page handed to it, so page 2 of the public register still
 * shows the right number.
 */
class DecisionNumberer
{
    /**
     * Build a map of decision id → Decision N string for every decision in
     * the given collection, resolving numbers against every decision that
     * shares the reference.
     *
     * @param  iterable<Decision>  $decisions
     * @return array<int, string>
     */
    public function label(iterable $decisions): array
    {
        $references = Collection::make($decisions)
            ->map(fn (Decision $decision) => $this->normalize($decision->reference))
            ->filter(fn (?string $reference) => $reference !== null)
            ->unique()
            ->values();

        if ($references->isEmpty()) {
            return [];
        }

        $rows = Decision::query()
            ->whereIn('reference', $references->all())
            ->orderBy('reference')
            ->orderBy('id')
            ->get(['id', 'reference']);

        $labels = [];
        $counters = [];
        foreach ($rows as $row) {
            $reference = $this->normalize($row->reference);
            if ($reference === null) {
                continue;
            }

            $counters[$reference] = ($counters[$reference] ?? 0) + 1;
            $labels[(int) $row->id] = 'Decision '.$counters[$reference];
        }

        return $labels;
    }

    private function normalize(?string $reference): ?string
    {
        if ($reference === null) {
            return null;
        }

        $trimmed = trim($reference);

        return $trimmed === '' ? null : $trimmed;
    }
}
