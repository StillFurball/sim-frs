<?php

namespace App\Support;

/**
 * Outcome of a planner run: the ranked combinations plus explanations when the search came up short.
 */
final readonly class PlannerResult
{
    /**
     * @param  list<ScheduleCombination>  $combinations  Best first.
     * @param  list<string>  $warnings  Human readable reasons why fewer (or no) combinations were found.
     * @param  bool  $truncated  Whether the search stopped at its node budget before exploring everything.
     */
    public function __construct(
        public array $combinations,
        public array $warnings,
        public int $exploredNodes,
        public bool $truncated,
    ) {}
}
