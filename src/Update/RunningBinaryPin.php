<?php

declare(strict_types=1);

namespace App\Update;

/**
 * A second name for the binary a long-running Airlock runs from. The static PHP runtime reopens its own file, by path,
 * each time it loads more of Airlock's code: once airlock update or install.sh puts a new binary at that path, an MCP
 * server still running would read the new file at the old one's offsets, and die. Run through a hard link only it
 * uses, it keeps reading the binary it started from.
 *
 * A pin is named after the process it runs, which keeps its pid across exec. It stays when that process ends, since
 * Airlock may load code from it until the very end: the next pin made beside it removes it.
 */
final readonly class RunningBinaryPin
{
    private const string PREFIX = '.airlock-run-';

    /** ESRCH, the same on Linux and macOS: PHP's posix extension names no errno. */
    private const int NO_SUCH_PROCESS = 3;

    /**
     * Links the binary under a name for this process, beside it, and removes the pins of processes that ended.
     *
     * @return string the pin
     *
     * @throws PinFailedException when the binary's directory cannot hold a pin (not writable, no hard links)
     */
    public function pin(string $binaryPath, int $processId): string
    {
        $directory = \dirname($binaryPath);
        if (!is_writable($directory)) {
            throw new PinFailedException(\sprintf('%s is not writable.', $directory));
        }
        $this->removePinsOfEndedProcesses($directory);

        $pin = $directory.'/'.self::PREFIX.$processId;
        if (file_exists($pin)) {
            unlink($pin);
        }
        if (!link($binaryPath, $pin)) {
            throw new PinFailedException(\sprintf('Could not link %s to %s.', $binaryPath, $pin));
        }

        return $pin;
    }

    public function isPin(string $binaryPath): bool
    {
        return 1 === preg_match('/^'.preg_quote(self::PREFIX, '/').'[0-9]+$/', basename($binaryPath));
    }

    private function removePinsOfEndedProcesses(string $directory): void
    {
        $pins = glob($directory.'/'.self::PREFIX.'*');
        if (false === $pins) {
            return;
        }
        foreach ($pins as $pin) {
            if ($this->isPin($pin) && !$this->isRunning((int) substr(basename($pin), \strlen(self::PREFIX)))) {
                unlink($pin);
            }
        }
    }

    private function isRunning(int $processId): bool
    {
        return posix_kill($processId, 0) || self::NO_SUCH_PROCESS !== posix_get_last_error();
    }
}
