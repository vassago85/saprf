<?php

namespace App\Services\AmmoString\DTO;

/**
 * A single editorial finding produced by the analyser. Rendered in the UI
 * as a bordered card with a coloured margin, in the same style as the
 * ladder verdict panel.
 */
final readonly class StringFinding
{
    public const SEVERITY_OK = 'ok';

    public const SEVERITY_WARN = 'warn';

    public const SEVERITY_BAD = 'bad';

    public function __construct(
        public string $severity,
        public string $title,
        public string $body,
    ) {}
}
