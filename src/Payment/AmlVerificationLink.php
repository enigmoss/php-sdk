<?php

declare(strict_types=1);

namespace Enigmoss\Payment;

use Enigmoss\Internal\Payload;

/**
 * A link where the customer of a locked payment passes the AML verification.
 */
final class AmlVerificationLink
{
    /**
     * @internal
     */
    public function __construct(
        public readonly ?string $url,
        public readonly ?AmlVerificationLinkStatus $status,
        public readonly ?\DateTimeImmutable $expiresAt,
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $p): self
    {
        return new self(
            $p->nullableString('url'),
            $p->nullableEnum('status', AmlVerificationLinkStatus::class, AmlVerificationLinkStatus::Unknown),
            $p->nullableDateTime('expires_at'),
        );
    }
}
