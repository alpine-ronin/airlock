<?php

declare(strict_types=1);

namespace App\Configuration;

/**
 * One of Airlock's settings, set with `airlock configure name=value`: read from its environment variable when set,
 * else from `settings.yaml`, else its default.
 */
enum Setting: string
{
    case RateLimit = 'rate_limit';

    public function environmentVariable(): string
    {
        return match ($this) {
            self::RateLimit => 'AIRLOCK_RATE_LIMIT',
        };
    }

    /**
     * @return positive-int
     */
    public function default(): int
    {
        return match ($this) {
            self::RateLimit => 30,
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::RateLimit => 'commands grants may run in a minute without asking',
        };
    }

    public static function forEnvironmentVariable(string $name): ?self
    {
        foreach (self::cases() as $setting) {
            if ($setting->environmentVariable() === $name) {
                return $setting;
            }
        }

        return null;
    }
}
