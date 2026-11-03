<?php

declare(strict_types=1);

namespace Enigmoss\Project;

use Enigmoss\Internal\Payload;

/**
 * Brand name and logo shown to payers.
 */
final class Brand
{
    /**
     * @internal Built by the SDK; use Enigmoss\Testing\Fixtures in tests.
     */
    public function __construct(
        public readonly string $name,
        /** Null when the brand has no logo: show the name only. */
        public readonly ?BrandLogo $logo,
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $p): self
    {
        $logo = $p->nullableObject('logo');

        return new self(
            name: $p->string('name'),
            logo: $logo === null ? null : BrandLogo::fromPayload($logo),
        );
    }
}
