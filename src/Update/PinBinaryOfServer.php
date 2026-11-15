<?php

declare(strict_types=1);

namespace App\Update;

use App\Connect\AirlockExecutable;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Before the MCP server reads its first request, it starts again from a pin of its binary (RunningBinaryPin), so that
 * an update of Airlock leaves a running server working until its client restarts it.
 */
#[AsEventListener(ConsoleEvents::COMMAND)]
final readonly class PinBinaryOfServer
{
    private const string SERVER_COMMAND = 'mcp:server';

    public function __construct(
        private AirlockExecutable $executable,
        private RunningBinaryPin $pin,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ConsoleCommandEvent $event): void
    {
        $binaryPath = $this->unpinnedServerBinary($event);
        $input = $event->getInput();
        if (null === $binaryPath || !$input instanceof ArgvInput) {
            return;
        }

        try {
            $pin = $this->pin->pin($binaryPath, posix_getpid());
        } catch (PinFailedException $e) {
            $this->logger->warning('{reason} An update of Airlock will stop this MCP server.', ['reason' => $e->getMessage()]);

            return;
        }
        pcntl_exec($pin, $input->getRawTokens());

        $this->logger->warning('Could not run the MCP server from {pin}: an update of Airlock will stop it.', ['pin' => $pin]);
    }

    /**
     * The binary the MCP server runs from when it is not a pin yet, or null for any other command or a checkout.
     */
    private function unpinnedServerBinary(ConsoleCommandEvent $event): ?string
    {
        if (self::SERVER_COMMAND !== $event->getCommand()?->getName()) {
            return null;
        }
        $binaryPath = $this->executable->binaryPath();
        if (null === $binaryPath || $this->pin->isPin($binaryPath)) {
            return null;
        }

        return $binaryPath;
    }
}
