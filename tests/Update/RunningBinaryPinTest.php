<?php

declare(strict_types=1);

namespace App\Tests\Update;

use App\Update\PinFailedException;
use App\Update\RunningBinaryPin;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class RunningBinaryPinTest extends TestCase
{
    private const string ROOT = __DIR__.'/../../var/test-running-binary-pin';

    private const string BINARY = self::ROOT.'/bin/airlock';

    #[\Override]
    protected function setUp(): void
    {
        $filesystem = new Filesystem();
        $filesystem->remove(self::ROOT);
        $filesystem->dumpFile(self::BINARY, 'old');
    }

    #[\Override]
    protected function tearDown(): void
    {
        chmod(\dirname(self::BINARY), 0o755);
    }

    public function testAPinKeepsTheBinaryItStartedFromWhenANewOneIsRenamedOverIt(): void
    {
        $pin = new RunningBinaryPin()->pin(self::BINARY, posix_getpid());

        file_put_contents(self::ROOT.'/bin/.airlock-update-new', 'new');
        rename(self::ROOT.'/bin/.airlock-update-new', self::BINARY);

        self::assertSame(self::ROOT.'/bin/.airlock-run-'.posix_getpid(), $pin);
        self::assertSame('old', file_get_contents($pin));
        self::assertSame('new', file_get_contents(self::BINARY));
    }

    public function testAPinIsTheSameFileNotACopy(): void
    {
        $pin = new RunningBinaryPin()->pin(self::BINARY, posix_getpid());

        self::assertSame(fileinode(self::BINARY), fileinode($pin));
    }

    public function testThePinsOfEndedProcessesAreRemovedAndThoseOfRunningOnesKept(): void
    {
        $endedProcess = $this->endedProcessId();
        $runningProcess = posix_getpid();
        link(self::BINARY, self::ROOT.'/bin/.airlock-run-'.$endedProcess);
        link(self::BINARY, self::ROOT.'/bin/.airlock-run-'.$runningProcess);
        file_put_contents(self::ROOT.'/bin/.airlock-run-notes', 'not a pin');

        new RunningBinaryPin()->pin(self::BINARY, $runningProcess);

        self::assertFileDoesNotExist(self::ROOT.'/bin/.airlock-run-'.$endedProcess);
        self::assertFileExists(self::ROOT.'/bin/.airlock-run-'.$runningProcess);
        self::assertFileExists(self::ROOT.'/bin/.airlock-run-notes', 'only pins are removed');
    }

    public function testABinaryInADirectoryItCannotWriteIsNotPinned(): void
    {
        chmod(\dirname(self::BINARY), 0o555);

        $this->expectException(PinFailedException::class);
        $this->expectExceptionMessageIsOrContains('is not writable');

        new RunningBinaryPin()->pin(self::BINARY, posix_getpid());
    }

    public function testOnlyAPinIsRecognisedAsOne(): void
    {
        $pin = new RunningBinaryPin();

        self::assertTrue($pin->isPin('/home/me/.local/bin/.airlock-run-4242'));
        self::assertFalse($pin->isPin('/home/me/.local/bin/airlock'));
        self::assertFalse($pin->isPin('/home/me/.local/bin/.airlock-update-x1y2'));
    }

    private function endedProcessId(): int
    {
        $process = new Process(['true']);
        $process->start();
        $processId = $process->getPid();
        $process->wait();
        self::assertNotNull($processId);

        return $processId;
    }
}
