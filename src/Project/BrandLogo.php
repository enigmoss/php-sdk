<?php

declare(strict_types=1);

namespace Enigmoss\Project;

use Enigmoss\Internal\Payload;

/**
 * Brand logo. Download it once and keep a local copy: sha256 changes when the image changes.
 */
final class BrandLogo
{
    /**
     * @internal Built by the SDK; use Enigmoss\Testing\Fixtures in tests.
     */
    public function __construct(
        /** Absolute URL of the image. */
        public readonly string $url,
        /** Hex SHA-256 of the image bytes; compare it after downloading. */
        public readonly string $sha256,
        /** Media type, e.g. "image/png" or "image/svg+xml". */
        public readonly string $mime,
        /** Size in pixels. */
        public readonly int $width,
        public readonly int $height,
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $p): self
    {
        return new self(
            url: $p->string('url'),
            sha256: $p->string('sha256'),
            mime: $p->string('mime'),
            width: $p->int('width'),
            height: $p->int('height'),
        );
    }
}
