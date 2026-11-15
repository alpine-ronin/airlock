<?php

declare(strict_types=1);

namespace App\Configuration;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;

/**
 * `%env(setting:AIRLOCK_RATE_LIMIT)%`: the environment variable when it is set, else what `airlock configure` wrote in
 * `settings.yaml`, else the setting's default. A file that cannot be read leaves the defaults, and is logged.
 */
final class SettingEnvVarProcessor implements EnvVarProcessorInterface
{
    private ?Settings $fileSettings = null;

    public function __construct(
        private readonly SettingsFile $file,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[\Override]
    public function getEnv(string $prefix, string $name, \Closure $getEnv): string
    {
        $setting = Setting::forEnvironmentVariable($name);
        if (!$setting instanceof Setting) {
            throw new \LogicException(\sprintf('"%s" is not a setting.', $name));
        }

        $fromEnvironment = $getEnv($name);
        if (\is_string($fromEnvironment) && '' !== $fromEnvironment) {
            return $fromEnvironment;
        }

        return (string) ($this->fileSettings()->get($setting) ?? $setting->default());
    }

    #[\Override]
    public static function getProvidedTypes(): array
    {
        return ['setting' => 'string'];
    }

    private function fileSettings(): Settings
    {
        if ($this->fileSettings instanceof Settings) {
            return $this->fileSettings;
        }

        try {
            $this->fileSettings = $this->file->read();
        } catch (InvalidSettingsException $e) {
            $this->logger->warning('Airlock keeps the default settings: {reason}', ['reason' => $e->getMessage()]);
            $this->fileSettings = new Settings();
        }

        return $this->fileSettings;
    }
}
