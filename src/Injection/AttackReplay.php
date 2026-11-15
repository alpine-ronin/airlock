<?php

declare(strict_types=1);

namespace App\Injection;

use App\Configuration\ConfigDirectory;
use App\Execution\ProjectDirectory;
use App\Grant\CommandSources;
use App\Grant\GrantMiss;
use App\Grant\GrantRules;
use App\Grant\Grants;
use App\Grant\ProjectGrants;
use App\Grant\SessionGrants;
use App\Policy\CommandPolicy;
use App\Policy\GitCommands;
use App\Policy\PolicyFiles;
use App\Policy\ProjectGitAliases;
use App\Policy\SecretFiles;
use App\Policy\Verdict;
use App\Shell\ShellParser;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Replays a scenario through Airlock's own parser, policy and grants, as `run_command` weighs a call, in a project and
 * a configuration directory made for it and removed after: the user's own policy and grants play no part. Nothing runs.
 *
 * The project-bound classes are built here rather than taken from the container, which binds them to the project the
 * server was started in.
 */
final readonly class AttackReplay
{
    private const string NOT_COVERED = 'Not covered by any grant yet.';

    public function __construct(
        private ShellParser $parser,
        private SecretFiles $secretFiles,
        private LockFactory $lockFactory,
        private RateLimiterFactoryInterface $grantedCommandsLimiter,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function replay(AttackScenario $scenario): ReplayResult
    {
        $root = $this->filesystem->tempnam(sys_get_temp_dir(), 'airlock-attack-');
        $this->filesystem->remove($root);
        $this->filesystem->mkdir([$root.'/project', $root.'/config'], 0o700);
        try {
            return $this->replayIn($scenario, $root);
        } finally {
            $this->filesystem->remove($root);
        }
    }

    private function replayIn(AttackScenario $scenario, string $root): ReplayResult
    {
        $project = new ProjectDirectory($root.'/project');
        $config = new ConfigDirectory($root, $root.'/config');
        $this->write($project, $scenario->files);
        if (null !== $scenario->policy) {
            $this->filesystem->dumpFile($project->path.'/.airlock.yaml', $scenario->policy);
        }

        $sources = new CommandSources($project);
        $sessionGrants = new SessionGrants();
        $grantRules = new GrantRules($sources);
        foreach ($scenario->granted as $granted) {
            $sessionGrants->add($grantRules->for($this->parser->parse($granted)));
        }
        $this->write($project, $scenario->edits);

        $policy = new CommandPolicy($this->secretFiles, new GitCommands(new ProjectGitAliases($project), $this->parser));
        $grants = new Grants($sessionGrants, new ProjectGrants($config, $project, $this->lockFactory), $sources);
        $line = $this->parser->parse($scenario->command);
        $assessment = $policy->assess($line, new PolicyFiles($config, $project, $this->parser)->refusals());

        return match ($assessment->verdict) {
            Verdict::Refused => new ReplayResult($scenario, ReplayOutcome::Refused, $this->inProject($project, $assessment->reasons)),
            Verdict::AskEveryTime => new ReplayResult($scenario, ReplayOutcome::AskedEveryTime, $assessment->reasons),
            Verdict::Plain => $grants->covers($line)
                ? $this->underGrant($scenario, $root)
                : new ReplayResult($scenario, ReplayOutcome::Asked, [$this->whyAsked($grants->miss($line))]),
        };
    }

    /**
     * A command a grant covers runs unasked, unless the agent called it so often that the rate limit stops it: the
     * server's own limiter, counting for this scenario alone.
     */
    private function underGrant(AttackScenario $scenario, string $root): ReplayResult
    {
        if (!$scenario->repeatsPastTheRateLimit) {
            return new ReplayResult($scenario, ReplayOutcome::RunsUnasked, []);
        }

        $limiter = $this->grantedCommandsLimiter->create($root);
        $limiter->consume($limiter->consume(0)->getRemainingTokens());
        $next = $limiter->consume();
        if ($next->isAccepted()) {
            return new ReplayResult($scenario, ReplayOutcome::RunsUnasked, []);
        }

        return new ReplayResult($scenario, ReplayOutcome::Asked, [GrantMiss::rateLimited($next->getLimit())->reason]);
    }

    /**
     * The reasons with the throwaway project's path left out: it is gone once the replay ends.
     *
     * @param list<string> $reasons
     *
     * @return list<string>
     */
    private function inProject(ProjectDirectory $project, array $reasons): array
    {
        return array_map(static fn (string $reason): string => str_replace($project->path.'/', '', $reason), $reasons);
    }

    private function whyAsked(?GrantMiss $miss): string
    {
        return $miss->reason ?? self::NOT_COVERED;
    }

    /**
     * @param array<string, string> $files
     */
    private function write(ProjectDirectory $project, array $files): void
    {
        foreach ($files as $path => $content) {
            if (str_starts_with($path, '/') || \in_array('..', explode('/', $path), true)) {
                throw new InvalidCorpusException(\sprintf('The corpus names a file outside its project: "%s".', $path));
            }
            $this->filesystem->dumpFile($project->path.'/'.$path, $content);
        }
    }
}
