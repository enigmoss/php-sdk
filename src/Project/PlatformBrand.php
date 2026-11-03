<?php

declare(strict_types=1);

namespace Enigmoss\Project;

use Enigmoss\Internal\Payload;

/**
 * The brand to show your payers next to the payment form: its name, logo and the form address.
 *
 * The brand follows the payment form domain your next payments get, so it can change without a
 * release on your side. Cache the result for refreshAfter seconds and refresh it after that.
 */
final class PlatformBrand
{
    /**
     * @internal Built by the SDK; use Enigmoss\Testing\Fixtures in tests.
     */
    public function __construct(
        /** Null when there is no brand to show: render a neutral payment button. */
        public readonly ?Brand $brand,
        /** Origin of the payment form, e.g. "https://pay.example.com". */
        public readonly string $payformOrigin,
        /** How long the result may be cached, in seconds. */
        public readonly int $refreshAfter,
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $p): self
    {
        $brand = $p->nullableObject('brand');

        return new self(
            brand: $brand === null ? null : Brand::fromPayload($brand),
            payformOrigin: $p->object('payform')->string('origin'),
            refreshAfter: $p->int('refresh_after'),
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
}
