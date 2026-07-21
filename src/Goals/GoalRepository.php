<?php

namespace CorporateIp\Insights\Goals;

use Illuminate\Support\Collection;
use Statamic\Facades\YAML;
use Statamic\Support\Str;

/**
 * Goals live in a flat YAML file (content/insights/goals.yaml by default) -
 * version-controlled and Git-integration friendly, like the rest of Statamic.
 * Registered as a singleton; the in-memory copy is reset on every write.
 */
class GoalRepository
{
    private ?Collection $loaded = null;

    /** @return Collection<int, Goal> */
    public function all(): Collection
    {
        return $this->loaded ??= collect(
            file_exists($this->path()) ? YAML::file($this->path())->parse() : [],
        )->map(fn ($data) => Goal::fromArray($data))->values();
    }

    public function find(string $handle): ?Goal
    {
        return $this->all()->first(fn (Goal $goal) => $goal->handle === $handle);
    }

    public function save(Goal $goal): void
    {
        $this->write($this->all()->reject(fn (Goal $existing) => $existing->handle === $goal->handle)->push($goal));
    }

    public function delete(string $handle): void
    {
        $this->write($this->all()->reject(fn (Goal $goal) => $goal->handle === $handle));
    }

    /** @param  iterable<Goal>  $goals */
    public function replaceAll(iterable $goals): void
    {
        $this->write(collect($goals));
    }

    public function makeHandle(string $name): string
    {
        $base = Str::slug($name) ?: 'goal';
        $handle = $base;

        for ($i = 2; $this->find($handle); $i++) {
            $handle = "{$base}-{$i}";
        }

        return $handle;
    }

    private function write(Collection $goals): void
    {
        $directory = dirname($this->path());

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($this->path(), YAML::dump($goals->map->toArray()->values()->all()));

        $this->loaded = null;
    }

    private function path(): string
    {
        return config('insights.storage_path').'/goals.yaml';
    }
}
