<?php

declare(strict_types=1);

namespace App\Command;

use App\Injection\AttackCorpus;
use App\Injection\AttackReplay;
use App\Injection\InvalidCorpusException;
use App\Injection\ReplayOutcome;
use App\Injection\ReplayResult;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Replays the public corpus of injected commands and shows what Airlock does with each, and why. Nothing runs: each
 * command is weighed as `run_command` weighs it, in a throwaway project.
 */
#[AsCommand(name: 'attacks', description: 'Replay the corpus of injected commands and show what Airlock does with each; nothing runs')]
final class AttacksCommand extends Command
{
    public function __construct(
        private readonly AttackCorpus $corpus,
        private readonly AttackReplay $replay,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $scenarios = $this->corpus->scenarios();
        } catch (InvalidCorpusException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $results = [];
        foreach ($scenarios as $scenario) {
            $results[] = $this->replay->replay($scenario);
        }

        $io->title('What an injection gets an agent to run, and what Airlock does');
        foreach ($results as $result) {
            $io->writeln($this->block($result));
        }
        $io->text('Nothing was run: each command went through the parser, the policy and the grants, in a throwaway project where the user had already allowed what the scenario grants. The scenarios: '.$this->corpus->path);

        return $this->conclude($io, $results);
    }

    /**
     * The outcome and where the injection was planted, the command as the agent would run it, then why.
     *
     * @return list<string>
     */
    private function block(ReplayResult $result): array
    {
        $style = ReplayOutcome::RunsUnasked === $result->outcome ? 'error' : 'info';
        $outcome = \sprintf('<%s>%s</%s>', $style, $result->outcome->label(), $style);
        if (!$result->isAsExpected()) {
            $outcome .= ' <comment>(expected: '.$result->scenario->expected->label().')</comment>';
        }

        $lines = [$outcome.', planted in '.OutputFormatter::escape($result->scenario->plantedIn), '  $ '.OutputFormatter::escape($result->scenario->command)];
        foreach ($result->reasons as $reason) {
            $lines[] = '  '.OutputFormatter::escape($reason);
        }
        $lines[] = '';

        return $lines;
    }

    /**
     * @param list<ReplayResult> $results
     */
    private function conclude(SymfonyStyle $io, array $results): int
    {
        $counts = array_fill_keys(array_map(static fn (ReplayOutcome $outcome): string => $outcome->value, ReplayOutcome::cases()), 0);
        $unexpected = 0;
        foreach ($results as $result) {
            ++$counts[$result->outcome->value];
            if (!$result->isAsExpected()) {
                ++$unexpected;
            }
        }
        $summary = \sprintf(
            '%d injected commands: %d refused, %d asked every time, %d asked.',
            \count($results),
            $counts[ReplayOutcome::Refused->value],
            $counts[ReplayOutcome::AskedEveryTime->value],
            $counts[ReplayOutcome::Asked->value],
        );

        if (0 !== $counts[ReplayOutcome::RunsUnasked->value]) {
            $io->error(\sprintf('%s %d would run without asking.', $summary, $counts[ReplayOutcome::RunsUnasked->value]));

            return Command::FAILURE;
        }
        if (0 !== $unexpected) {
            $io->warning(\sprintf('%s None would run without asking, but %d differ from what the corpus expects.', $summary, $unexpected));

            return Command::FAILURE;
        }
        $io->success($summary.' None would run without asking.');

        return Command::SUCCESS;
    }
}
