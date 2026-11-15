<?php

declare(strict_types=1);

namespace App\Injection;

final readonly class ReplayResult
{
    /**
     * @param list<string> $reasons why Airlock refuses or asks, as the form would say it
     */
    public function __construct(
        public AttackScenario $scenario,
        public ReplayOutcome $outcome,
        public array $reasons,
    ) {
    }

    public function isAsExpected(): bool
    {
        return $this->outcome === $this->scenario->expected;
    }
}
