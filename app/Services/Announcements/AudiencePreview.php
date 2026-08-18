<?php

namespace App\Services\Announcements;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Immutable preview payload returned by AudienceResolver::preview().
 *
 * `count` is the total unique recipient count that would be frozen if
 * the announcement were sent right now. `sample` is a small slice of
 * the corresponding User models for display in the composer — first
 * name, email, and role. It intentionally does NOT contain every user
 * so the preview stays fast for big audiences (e.g. all active members).
 */
final class AudiencePreview
{
    /**
     * @param  Collection<int, User>  $sample
     */
    public function __construct(
        public readonly int $count,
        public readonly Collection $sample,
    ) {}
}
