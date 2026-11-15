<?php

declare(strict_types=1);

namespace App\Configuration;

/**
 * The settings `airlock configure name=value` saved; a setting left out takes its default.
 */
final readonly class Settings
{
    /**
     * @param array<value-of<Setting>, positive-int> $values
     */
    public function __construct(
        private array $values = [],
    ) {
    }

    /**
     * @return positive-int|null
     */
    public function get(Setting $setting): ?int
    {
        return $this->values[$setting->value] ?? null;
    }

    /**
     * @throws \InvalidArgumentException when the value is not positive
     */
    public function with(Setting $setting, int $value): self
    {
        if ($value < 1) {
            throw new \InvalidArgumentException(\sprintf('%s must be positive, got %d.', $setting->value, $value));
        }

        return new self([...$this->values, $setting->value => $value]);
    }

    /**
     * @return array<value-of<Setting>, positive-int>
     */
    public function toArray(): array
    {
        return $this->values;
    }
}
