<?php

declare(strict_types=1);

namespace Enigmoss\PaymentMethod;

use Enigmoss\Auth\KeyScope;
use Enigmoss\Internal\Assert;
use Enigmoss\Internal\Endpoint;
use Enigmoss\Internal\Payload;
use Enigmoss\Internal\Requester;
use Enigmoss\RequestOptions;

final class PaymentMethodService
{
    /**
     * @internal
     */
    public function __construct(private readonly Requester $requester) {}

    /**
     * Payment methods of your tariff. GET /api/v4/payment-methods, either key.
     *
     * @return list<PaymentMethod>
     *
     * @throws \Enigmoss\Exception\EnigmossException
     */
    public function list(?RequestOptions $options = null): array
    {
        $result = $this->requester->execute(Endpoint::get('/payment-methods', KeyScope::Any), options: $options);

        return array_map(static fn(Payload $p): PaymentMethod => PaymentMethod::fromPayload($p), $result->payloads());
    }

    /**
     * Sets the discount (positive) or surcharge (negative) of a payment method, -99..90 percent.
     * PATCH /api/v4/payment-methods/{currency}/{network}, payment key, permission payment_settings.
     *
     * @throws \Enigmoss\Exception\NotFoundException
     * @throws \Enigmoss\Exception\EnigmossException
     */
    public function updateDiscount(string $currencyCode, string $networkCode, int $discountPercent, ?RequestOptions $options = null): PaymentMethod
    {
        Assert::range($discountPercent, 'discountPercent', -99, 90);
        $result = $this->requester->execute(
            Endpoint::patch('/payment-methods/{currency_code}/{network_code}', KeyScope::Payment),
            ['currency_code' => $currencyCode, 'network_code' => $networkCode],
            body: ['discount_percent' => $discountPercent],
            options: $options,
        );

        return PaymentMethod::fromPayload($result->payload());
    }
}
