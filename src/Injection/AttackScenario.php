<?php

declare(strict_types=1);

namespace App\Injection;

/**
 * One command of the corpus: what an injection gets an agent to run, in a project where the user already allowed
 * `granted`, after the agent made `edits`.
 */
final readonly class AttackScenario
{
    /**
     * @param list<string>          $granted commands the user allowed for the session beforehand
     * @param array<string, string> $files   project files, by path, before the grants
     * @param array<string, string> $edits   project files the injection had the agent change after the grants
     * @param string|null           $policy  the project's `.airlock.yaml`, when it has one
     */
    public function __construct(
        public string $id,
        public string $plantedIn,
        public string $command,
        public ReplayOutcome $expected,
        public array $granted = [],
        public array $files = [],
        public array $edits = [],
        public ?string $policy = null,
    ) {
    }
}
