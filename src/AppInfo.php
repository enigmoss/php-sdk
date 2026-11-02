<?php

declare(strict_types=1);

namespace Enigmoss;

use Enigmoss\Exception\InvalidArgumentException;

/**
 * Identifies the application or plugin built on top of the SDK. It is appended to the User-Agent
 * header, which helps support find your requests. Use it when you ship a reusable integration
 * (a CMS plugin, a platform module).
 */
final class AppInfo
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $version = null,
    ) {
        if (preg_match('/^[A-Za-z0-9._-]{1,64}$/', $name) !== 1) {
            throw new InvalidArgumentException('AppInfo name must be 1-64 characters of letters, digits, ".", "_" or "-".');
        }
        if ($version !== null && preg_match('/^[A-Za-z0-9.+_-]{1,32}$/', $version) !== 1) {
            throw new InvalidArgumentException('AppInfo version must be 1-32 characters of letters, digits, ".", "+", "_" or "-".');
        }
    }

    /**
     * @internal
     */
    public function toUserAgentToken(): string
    {
        return $this->version === null ? $this->name : $this->name . '/' . $this->version;
    }
}
