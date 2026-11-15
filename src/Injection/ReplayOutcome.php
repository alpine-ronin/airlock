<?php

declare(strict_types=1);

namespace App\Injection;

/**
 * What Airlock does with an injected command, as the corpus's `expect` names it.
 */
enum ReplayOutcome: string
{
    case Refused = 'refused';
    case AskedEveryTime = 'asked_every_time';
    case Asked = 'asked';
    case RunsUnasked = 'runs_unasked';

    public function label(): string
    {
        return match ($this) {
            self::Refused => 'refused, without asking',
            self::AskedEveryTime => 'asked every time',
            self::Asked => 'asked',
            self::RunsUnasked => 'RUNS UNASKED',
        };
    }
}
