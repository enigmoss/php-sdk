<?php

declare(strict_types=1);

namespace Enigmoss\Webhook;

use Enigmoss\Auth\KeyScope;
use Enigmoss\Event\EventRecord;
use Enigmoss\Event\WebhookEvent;
use Enigmoss\Exception\ConfigurationException;
use Enigmoss\Internal\Endpoint;
use Enigmoss\Internal\Requester;
use Enigmoss\RequestOptions;
use Enigmoss\Retry\ClockInterface;

final class WebhookService
{
    /**
     * @internal
     */
    public function __construct(
        private readonly Requester $requester,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * Verifies an incoming webhook with the configured payment key and returns the typed event.
     *
     *     $event = $enigmoss->webhooks->constructEvent(file_get_contents('php://input'), WebhookVerifier::headersFromGlobals());
     *
     * @param array<string, string|null|array<array-key, string|null>> $headers
     *
     * @throws \Enigmoss\Exception\WebhookVerificationException Answer it with HTTP 400.
     */
    public function constructEvent(string $rawBody, array $headers): WebhookEvent
    {
        return $this->verifier()->constructEvent($rawBody, $headers);
    }

    /**
     * A verifier with the configured payment key. During a key rotation build
     * new WebhookVerifier($projectId, [$newKey, $oldKey]) yourself.
     */
    public function verifier(): WebhookVerifier
    {
        $config = $this->requester->config();
        if ($config->paymentKey === null) {
            throw new ConfigurationException('Webhooks are signed with the payment key; pass paymentKey to ClientConfig.');
        }

        return new WebhookVerifier($config->projectId, $config->paymentKey, $config->webhookTolerance, $this->clock);
    }

    /**
     * Sends a webhook.test event to $callbackUrl or the project default URL, once, without
     * retries. Returns the event with the delivery attempt and your endpoint's response.
     * POST /api/v4/webhooks/test, payment key, permission webhooks. 10 per minute.
     *
     * @throws \Enigmoss\Exception\EnigmossException
     */
    public function sendTest(?string $callbackUrl = null, ?RequestOptions $options = null): EventRecord
    {
        $result = $this->requester->execute(
            Endpoint::nonIdempotent('/webhooks/test', KeyScope::Payment),
            body: $callbackUrl === null ? [] : ['callback_url' => $callbackUrl],
            options: $options,
        );

        return EventRecord::fromPayload($result->payload());
    }
}
