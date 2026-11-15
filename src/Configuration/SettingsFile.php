<?php

declare(strict_types=1);

namespace App\Configuration;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * `settings.yaml`, in Airlock's directory: out of every command's reach, so an agent cannot loosen its own limits.
 */
final readonly class SettingsFile
{
    public function __construct(
        private ConfigDirectory $configDirectory,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function path(): string
    {
        return $this->configDirectory->path.'/settings.yaml';
    }

    /**
     * @throws InvalidSettingsException when the file exists but does not hold settings
     */
    public function read(): Settings
    {
        $path = $this->path();
        if (!is_file($path)) {
            return new Settings();
        }

        try {
            $content = Yaml::parseFile($path);
        } catch (ParseException $e) {
            throw new InvalidSettingsException(\sprintf('%s is not valid YAML at line %d.', $path, $e->getParsedLine()), $e->getCode(), previous: $e);
        }
        if (null === $content) {
            return new Settings();
        }
        if (!\is_array($content) || ([] !== $content && array_is_list($content))) {
            throw new InvalidSettingsException(\sprintf('%s holds no settings.', $path));
        }

        $settings = new Settings();
        foreach ($content as $name => $value) {
            // A setting a later version added is left to it, so going back a version keeps the others.
            $setting = Setting::tryFrom((string) $name);
            if (!$setting instanceof Setting) {
                continue;
            }
            if (!\is_int($value) || $value < 1) {
                throw new InvalidSettingsException(\sprintf('%s: "%s" is not a positive whole number.', $path, $name));
            }
            $settings = $settings->with($setting, $value);
        }

        return $settings;
    }

    public function write(Settings $settings): void
    {
        $this->filesystem->mkdir($this->configDirectory->path, 0o700);
        $this->filesystem->chmod($this->configDirectory->path, 0o700);
        $this->filesystem->dumpFile($this->path(), Yaml::dump($settings->toArray()));
    }
}
