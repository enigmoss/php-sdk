<?php

declare(strict_types=1);

namespace Enigmoss;

use Enigmoss\Exception\InvalidArgumentException;

/**
 * An API key secret that never leaks into logs, dumps, serialized payloads or stack traces.
 *
 * The value lives inside a closure: var_dump(), print_r() and var_export() show nothing useful,
 * and serialize() is refused so the key cannot end up in a queue payload or a cache entry.
 * Always pass keys to the SDK wrapped in this class instead of plain strings.
 */
final class Secret
{
    /** @var \Closure(): string */
    private \Closure $value;

    public function __construct(#[\SensitiveParameter] string $value)
    {
        if ($value === '') {
            throw new InvalidArgumentException('An API key secret must not be empty.');
        }

        $this->value = static fn(): string => $value;
    }

    /**
     * Returns the raw secret. Only for signing; never log or store the result.
     *
     * @internal
     */
    public function reveal(): string
    {
        return ($this->value)();
    }

    /**
     * @return array{value: string}
     */
    public function __debugInfo(): array
    {
        return ['value' => '********'];
    }

    public function __toString(): string
    {
        return '********';
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new \LogicException('Enigmoss\Secret cannot be serialized: keep API keys in configuration, not in serialized payloads.');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('Enigmoss\Secret cannot be unserialized.');
    }
}
