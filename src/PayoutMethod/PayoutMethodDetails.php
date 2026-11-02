<?php

declare(strict_types=1);

namespace Enigmoss\PayoutMethod;

use Enigmoss\Internal\Payload;

final class PayoutMethodDetails
{
    /**
     * @internal
     *
     * @param list<PriorityFee> $priorities
     */
    public function __construct(
        public readonly string $currencyCode,
        public readonly string $networkCode,
        public readonly ?string $networkName,
        /** e.g. "TRC-20"; null for a native coin. */
        public readonly ?string $tokenStandard,
        public readonly string $minAmount,
        public readonly string $maxAmount,
        public readonly int $decimals,
        public readonly bool $isMemoAvailable,
        public readonly array $priorities,
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $p): self
    {
        return new self(
            $p->string('currency_code'),
            $p->string('network_code'),
            $p->nullableString('network_name'),
            $p->nullableString('token_standard'),
            $p->decimal('min_amount'),
            $p->decimal('max_amount'),
            $p->int('decimals'),
            $p->bool('is_memo_available'),
            array_map(static fn(Payload $item): PriorityFee => PriorityFee::fromPayload($item), $p->objects('priorities')),
        );
    }
}
