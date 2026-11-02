<?php

declare(strict_types=1);

namespace Enigmoss\Webhook;

use Enigmoss\Auth\RequestSigner;
use Enigmoss\Event\WebhookEvent;
use Enigmoss\Exception\WebhookFailureReason;
use Enigmoss\Exception\WebhookVerificationException;
use Enigmoss\Retry\ClockInterface;
use Enigmoss\Retry\SystemClock;
use Enigmoss\Secret;

/**
 * Verifies incoming webhooks: X-Project-Uuid must be your project, X-Timestamp fresh, X-Signature
 * the HMAC-SHA256 of "{timestamp}\n{project}\n{raw body}" with your payment key.
 *
 * Pass several secrets while rotating the payment key: the old and the new one.
 * Always verify the RAW body bytes, never a re-encoded array.
 */
final class WebhookVerifier
{
    /** @var list<Secret> */
    private readonly array $secrets;

    private readonly string $projectId;

    private readonly ClockInterface $clock;

    /**
     * @param Secret|list<Secret> $secrets   Current payment key, plus the previous one during rotation.
     * @param int                 $tolerance Accepted age of X-Timestamp, seconds.
     */
    public function __construct(
        string $projectId,
        Secret|array $secrets,
        private readonly int $tolerance = 300,
        ?ClockInterface $clock = null,
    ) {
        $this->projectId = strtolower($projectId);
        $this->secrets = $secrets instanceof Secret ? [$secrets] : $secrets;
        $this->clock = $clock ?? new SystemClock();
        if ($this->secrets === []) {
            throw new \Enigmoss\Exception\ConfigurationException('WebhookVerifier needs at least one payment key secret.');
        }
    }

    /**
     * @param array<string, string|null|array<array-key, string|null>> $headers Request headers, any case (framework header bags fit as is).
     *
     * @throws WebhookVerificationException
     */
    public function verify(string $rawBody, array $headers): void
    {
        $headers = array_change_key_case($headers, CASE_LOWER);
        $project = self::header($headers, 'x-project-uuid');
        $timestamp = self::header($headers, 'x-timestamp');
        $signatures = array_filter((array) ($headers['x-signature'] ?? []), static fn(?string $value): bool => $value !== null && $value !== '');

        if ($project === null || $timestamp === null || $signatures === []) {
            $missing = array_keys(array_filter(['X-Project-Uuid' => $project === null, 'X-Timestamp' => $timestamp === null, 'X-Signature' => $signatures === []]));

            throw new WebhookVerificationException(
                \sprintf('The webhook has no %s header. Make sure the web server or proxy passes custom headers through.', implode(', ', $missing)),
                WebhookFailureReason::MissingHeader,
            );
        }
        if (strtolower($project) !== $this->projectId) {
            throw new WebhookVerificationException('The webhook is addressed to another project.', WebhookFailureReason::ProjectMismatch);
        }
        if (preg_match('/^\d{1,10}$/', $timestamp) !== 1 || abs((int) $this->clock->now() - (int) $timestamp) > $this->tolerance) {
            throw new WebhookVerificationException('The webhook timestamp is outside the accepted window.', WebhookFailureReason::TimestampOutOfTolerance);
        }

        foreach ($this->secrets as $secret) {
            // The signature is computed over the project value exactly as sent.
            $expected = RequestSigner::signWebhook($secret, $timestamp, $project, $rawBody);
            foreach ($signatures as $signature) {
                if (hash_equals($expected, strtolower(trim($signature)))) {
                    return;
                }
            }
        }

        throw new WebhookVerificationException(
            'The webhook signature does not match. Verify the raw request body (not a re-encoded array) with the payment key of this project.',
            WebhookFailureReason::SignatureMismatch,
        );
    }

    /**
     * Verifies the webhook and returns the typed event. After a valid signature it never throws
     * because of the event content: unknown types become UnknownEvent.
     *
     * @param array<string, string|null|array<array-key, string|null>> $headers
     *
     * @throws WebhookVerificationException
     */
    public function constructEvent(string $rawBody, array $headers): WebhookEvent
    {
        $this->verify($rawBody, $headers);

        $decoded = json_decode($rawBody, true, 512, JSON_BIGINT_AS_STRING);
        if (!\is_array($decoded)) {
            throw new WebhookVerificationException('The webhook body is not a JSON object.', WebhookFailureReason::MalformedPayload);
        }

        return WebhookEvent::fromEnvelope($decoded);
    }

    /**
     * Webhook headers of the current request in plain PHP ($_SERVER).
     *
     * @return array<string, string>
     */
    public static function headersFromGlobals(): array
    {
        $headers = [];
        foreach (['X-Project-Uuid' => 'HTTP_X_PROJECT_UUID', 'X-Timestamp' => 'HTTP_X_TIMESTAMP', 'X-Signature' => 'HTTP_X_SIGNATURE'] as $name => $key) {
            if (isset($_SERVER[$key]) && \is_string($_SERVER[$key])) {
                $headers[$name] = $_SERVER[$key];
            }
        }

        return $headers;
    }

    /**
     * @param array<string, string|null|array<array-key, string|null>> $headers
     */
    private static function header(array $headers, string $name): ?string
    {
        $value = $headers[$name] ?? null;
        if (\is_array($value)) {
            $value = reset($value);
            $value = $value === false ? null : $value;
        }

        return $value === null || $value === '' ? null : trim($value);
    }
}
