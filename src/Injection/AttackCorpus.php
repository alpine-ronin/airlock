<?php

declare(strict_types=1);

namespace App\Injection;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The public corpus of injected commands, `tests/Fixtures/injection/commands.yaml`, shipped in the binary too.
 */
final readonly class AttackCorpus
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/tests/Fixtures/injection/commands.yaml')]
        public string $path,
    ) {
    }

    /**
     * @return list<AttackScenario>
     *
     * @throws InvalidCorpusException
     */
    public function scenarios(): array
    {
        try {
            $corpus = Yaml::parseFile($this->path);
        } catch (ParseException $e) {
            throw new InvalidCorpusException(\sprintf('%s is not valid YAML: %s', $this->path, $e->getMessage()), $e->getCode(), previous: $e);
        }
        $entries = \is_array($corpus) ? $corpus['scenarios'] ?? null : null;
        if (!\is_array($entries)) {
            throw new InvalidCorpusException(\sprintf('%s has no "scenarios" list.', $this->path));
        }

        $scenarios = [];
        foreach ($entries as $entry) {
            if (!\is_array($entry)) {
                throw new InvalidCorpusException(\sprintf('%s: a scenario is not a map.', $this->path));
            }
            $scenarios[] = $this->scenario($entry);
        }

        return $scenarios;
    }

    /**
     * @param array<mixed> $entry
     */
    private function scenario(array $entry): AttackScenario
    {
        $id = $this->text($entry, 'id');
        $expected = ReplayOutcome::tryFrom($this->text($entry, 'expect'));
        if (!$expected instanceof ReplayOutcome || ReplayOutcome::RunsUnasked === $expected) {
            throw new InvalidCorpusException(\sprintf('%s: scenario "%s" expects neither refused, asked_every_time nor asked.', $this->path, $id));
        }
        $policy = $entry['policy'] ?? null;

        return new AttackScenario(
            $id,
            $this->text($entry, 'planted_in'),
            $this->text($entry, 'command'),
            $expected,
            $this->texts($entry, 'granted'),
            $this->files($entry, 'files'),
            $this->files($entry, 'edits'),
            \is_string($policy) ? $policy : null,
            true === ($entry['repeat_past_the_rate_limit'] ?? false),
        );
    }

    /**
     * @param array<mixed> $entry
     */
    private function text(array $entry, string $key): string
    {
        $value = $entry[$key] ?? null;
        if (!\is_string($value)) {
            throw new InvalidCorpusException(\sprintf('%s: a scenario has no "%s" text.', $this->path, $key));
        }

        return $value;
    }

    /**
     * @param array<mixed> $entry
     *
     * @return list<string>
     */
    private function texts(array $entry, string $key): array
    {
        $texts = [];
        foreach ((array) ($entry[$key] ?? []) as $value) {
            if (!\is_string($value)) {
                throw new InvalidCorpusException(\sprintf('%s: "%s" holds something other than text.', $this->path, $key));
            }
            $texts[] = $value;
        }

        return $texts;
    }

    /**
     * @param array<mixed> $entry
     *
     * @return array<string, string>
     */
    private function files(array $entry, string $key): array
    {
        $files = [];
        foreach ((array) ($entry[$key] ?? []) as $path => $content) {
            if (!\is_string($content)) {
                throw new InvalidCorpusException(\sprintf('%s: "%s" holds a file whose content is not text.', $this->path, $key));
            }
            $files[(string) $path] = $content;
        }

        return $files;
    }
}
