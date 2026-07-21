<?php

namespace CorporateIp\Insights\Support;

use Statamic\Facades\YAML;

/**
 * Insights settings (email report recipients, frequencies, ...) in a flat YAML
 * file next to the goals - editable in the CP, committed with the content.
 */
class Settings
{
    private ?array $loaded = null;

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    public function put(string $key, mixed $value): void
    {
        $this->write(array_merge($this->all(), [$key => $value]));
    }

    public function all(): array
    {
        return $this->loaded ??= file_exists($this->path())
            ? (YAML::file($this->path())->parse() ?: [])
            : [];
    }

    private function write(array $settings): void
    {
        $directory = dirname($this->path());

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($this->path(), YAML::dump($settings));

        $this->loaded = null;
    }

    private function path(): string
    {
        return config('insights.storage_path').'/settings.yaml';
    }
}
