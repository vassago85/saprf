<?php

namespace App\Services\Ladder\DTO;

/**
 * Overall verdict resolved from the spec's precedence order:
 *
 *   1. No step has n ≥ 2 → NothingTestable
 *   2. No adjacent pair separates → NoNodeSupported (also state rounds required)
 *   3. One or more pairs separate → NodesFound
 *
 * Copy is produced in the service so the view has no verdict logic.
 */
final readonly class LadderVerdict
{
    public const NOTHING_TESTABLE = 'nothing_testable';

    public const NO_NODE_SUPPORTED = 'no_node_supported';

    public const NODES_FOUND = 'nodes_found';

    /**
     * @param  list<LadderPairComparison>  $separatingPairs
     */
    public function __construct(
        public string $case,
        public string $text,
        public array $separatingPairs = [],
    ) {}
}
