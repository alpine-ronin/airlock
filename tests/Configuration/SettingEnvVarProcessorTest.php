<?php

declare(strict_types=1);

namespace App\Tests\Configuration;

use App\Configuration\ConfigDirectory;
use App\Configuration\Setting;
use App\Configuration\SettingEnvVarProcessor;
use App\Configuration\Settings;
use App\Configuration\SettingsFile;
use App\Tests\Output\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class SettingEnvVarProcessorTest extends TestCase
{
    private const string HOME = __DIR__.'/../../var/test-setting-processor';

    private SettingsFile $file;

    private RecordingLogger $logger;

    #[\Override]
    protected function setUp(): void
    {
        new Filesystem()->remove(self::HOME);
        $this->file = new SettingsFile(new ConfigDirectory(self::HOME));
        $this->logger = new RecordingLogger();
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove(self::HOME);
    }

    public function testTheEnvironmentWinsOverTheFile(): void
    {
        $this->file->write(new Settings()->with(Setting::RateLimit, 5));

        self::assertSame('12', $this->resolve('12'));
    }

    public function testAnEmptyVariableFallsBackToTheFile(): void
    {
        $this->file->write(new Settings()->with(Setting::RateLimit, 5));

        self::assertSame('5', $this->resolve(''));
    }

    public function testWithNeitherTheSettingTakesItsDefault(): void
    {
        self::assertSame('30', $this->resolve(null));
    }

    public function testAFileThatCannotBeReadLeavesTheDefaultAndIsLogged(): void
    {
        new Filesystem()->dumpFile($this->file->path(), "rate_limit: none\n");

        self::assertSame('30', $this->resolve(null));
        self::assertSame('warning', $this->logger->records[0]['level'] ?? null);
    }

    public function testOnlySettingsAreResolved(): void
    {
        $this->expectException(\LogicException::class);

        new SettingEnvVarProcessor($this->file, $this->logger)->getEnv('setting', 'HOME', static fn (): string => '');
    }

    private function resolve(?string $fromEnvironment): string
    {
        return new SettingEnvVarProcessor($this->file, $this->logger)->getEnv('setting', Setting::RateLimit->environmentVariable(), static fn (): ?string => $fromEnvironment);
    }
}
