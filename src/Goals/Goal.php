<?php

namespace CorporateIp\Insights\Goals;

use Illuminate\Contracts\Support\Arrayable;

/**
 * A conversion goal definition. Goals are evaluated at read time against raw
 * rows (retroactive within retention) and materialized nightly into
 * insights_daily_goals for longer ranges.
 *
 * Types:
 * - path:  matches pageview paths, wildcards allowed ("/bedankt", "/docs/*")
 * - event: matches a custom event name fired via window._insights.event()
 * - form:  matches submissions of a Statamic form handle (recorded server-side)
 */
class Goal implements Arrayable
{
    public const TYPES = ['path', 'event', 'form'];

    public function __construct(
        public readonly string $handle,
        public readonly string $name,
        public readonly string $type,
        public readonly string $value,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            handle: (string) $data['handle'],
            name: (string) ($data['name'] ?? $data['handle']),
            type: in_array($data['type'] ?? null, self::TYPES, true) ? $data['type'] : 'path',
            value: (string) ($data['value'] ?? ''),
        );
    }

    /**
     * The event name this goal matches, for the event-backed types.
     */
    public function eventName(): ?string
    {
        return match ($this->type) {
            'event' => $this->value,
            'form' => 'form:'.$this->value,
            default => null,
        };
    }

    /**
     * The path pattern as a LIKE pattern (* -> %). Paired with an explicit
     * "ESCAPE '!'" clause. The escape char is '!', not backslash: a backslash
     * escape works on SQLite but is a syntax error on MySQL/MariaDB (a lone
     * backslash escapes the closing quote). '!' has no special meaning inside a
     * string literal on any driver, and SQLite has no default escape character.
     */
    public function likePattern(): string
    {
        return str_replace(['!', '%', '_', '*'], ['!!', '!%', '!_', '%'], $this->value);
    }

    public function toArray(): array
    {
        return [
            'handle' => $this->handle,
            'name' => $this->name,
            'type' => $this->type,
            'value' => $this->value,
        ];
    }
}
