<?php

declare(strict_types=1);

namespace App\Tests\Configuration;

use App\Configuration\ConfigDirectory;
use App\Configuration\InvalidSettingsException;
use App\Configuration\Setting;
use App\Configuration\Settings;
use App\Configuration\SettingsFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class SettingsFileTest extends TestCase
{
    private const string HOME = __DIR__.'/../../var/test-settings-file';

    private SettingsFile $file;

    #[\Override]
    protected function setUp(): void
    {
        new Filesystem()->remove(self::HOME);
        $this->file = new SettingsFile(new ConfigDirectory(self::HOME));
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove(self::HOME);
    }

    public function testWithoutTheFileNoSettingIsSaved(): void
    {
        self::assertNull($this->file->read()->get(Setting::RateLimit));
    }

    public function testWhatIsWrittenIsReadBack(): void
    {
        $this->file->write(new Settings()->with(Setting::RateLimit, 5));

        self::assertSame(5, $this->file->read()->get(Setting::RateLimit));
        self::assertSame("rate_limit: 5\n", file_get_contents($this->file->path()));
    }

    public function testAnUnknownSettingIsIgnored(): void
    {
        new Filesystem()->dumpFile($this->file->path(), "later_setting: 5\nrate_limit: 7\n");

        self::assertSame(7, $this->file->read()->get(Setting::RateLimit));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function wrongFiles(): iterable
    {
        yield 'not YAML' => ["rate_limit: [\n", 'is not valid YAML'];
        yield 'a list' => ["- 5\n", 'holds no settings'];
        yield 'zero' => ["rate_limit: 0\n", '"rate_limit" is not a positive whole number'];
        yield 'a string' => ["rate_limit: '5'\n", '"rate_limit" is not a positive whole number'];
    }

    #[DataProvider('wrongFiles')]
    public function testAFileThatDoesNotHoldSettingsIsRefused(string $content, string $message): void
    {
        new Filesystem()->dumpFile($this->file->path(), $content);

        $this->expectException(InvalidSettingsException::class);
        $this->expectExceptionMessageIsOrContains($message);

        $this->file->read();
    }
}
