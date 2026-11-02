<?php

declare(strict_types=1);

namespace Enigmoss\Testing;

use Enigmoss\Auth\RequestSigner;
use Enigmoss\Secret;

/**
 * Builds signed webhook requests to test your webhook endpoint end to end.
 *
 *     $webhook = WebhookSimulator::sign(Fixtures::webhook('payment.paid', Fixtures::payment()), $projectId, $paymentKey);
 *     // Send the body bytes exactly as signed; re-encoding (e.g. Laravel's postJson) breaks the signature.
 *     $this->call('POST', '/webhooks/enigmoss', [], [], [], $webhook->serverVariables(), $webhook->body); // Laravel
 */
final class WebhookSimulator
{
    /**
     * @param string|array<string, mixed> $payload   The exact body, or an envelope to encode.
     * @param int|null                    $timestamp Defaults to now.
     */
    public static function sign(string|array $payload, string $projectId, Secret $paymentKey, ?int $timestamp = null): SignedWebhook
    {
        $body = \is_string($payload) ? $payload : json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = (string) ($timestamp ?? time());

        return new SignedWebhook($body, [
            'Content-Type' => 'application/json',
            'X-Project-Uuid' => $projectId,
            'X-Timestamp' => $timestamp,
            'X-Signature' => RequestSigner::signWebhook($paymentKey, $timestamp, $projectId, $body),
        ]);
    }
}
