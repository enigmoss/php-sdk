<?php

declare(strict_types=1);

namespace Enigmoss\Project;

use Enigmoss\Internal\Payload;

/**
 * The project the request was signed for.
 */
final class Project
{
    /**
     * @internal Built by the SDK; use Enigmoss\Testing\Fixtures in tests.
     *
     * @param list<string> $permissions
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        /** Which key signed the request. */
        public readonly KeyType $keyType,
        /** Permissions enabled for the project and reachable with this key type, e.g. "payout_create". */
        public readonly array $permissions,
        /** Server Unix time; compare with your clock to detect drift. */
        public readonly int $serverTime,
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $p): self
    {
        return new self(
            id: $p->string('id'),
            name: $p->string('name'),
            keyType: $p->enum('key_type', KeyType::class, KeyType::Unknown),
            permissions: $p->strings('permissions'),
            serverTime: $p->int('server_time'),
        );
    }

    /**
     * @internal
     *
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return self::fromPayload(new Payload($data));
    }

    public function can(string $permission): bool
    {
        return \in_array($permission, $this->permissions, true);
    }
}
