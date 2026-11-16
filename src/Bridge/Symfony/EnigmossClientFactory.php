<?php

declare(strict_types=1);

namespace Enigmoss\Bridge\Symfony;

use Enigmoss\Bridge\Psr3\LoggerObserver;
use Enigmoss\Bridge\Symfony\Http\CacheOriginStore;
use Enigmoss\Bridge\Symfony\Http\SymfonyHttpClientTransport;
use Enigmoss\ClientConfig;
use Enigmoss\EnigmossClient;
use Enigmoss\Secret;
use Enigmoss\Webhook\WebhookVerifier;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * @internal Service factory of EnigmossBundle.
 */
final class EnigmossClientFactory
{
    /**
     * @param array{base_url: string, project_id: string, payment_key: ?string, payout_key: ?string, timeout: float, connect_timeout: float, max_retries: int, max_retry_delay: float, webhook_tolerance: int, allow_insecure_http: bool, fallback_base_url?: ?string} $options
     */
    public static function create(
        array $options,
        ?HttpClientInterface $httpClient = null,
        ?LoggerInterface $logger = null,
        ?CacheItemPoolInterface $originCache = null,
    ): EnigmossClient {
        $fallback = $options['fallback_base_url'] ?? null;
        $fallback = $fallback === '' ? null : $fallback;

        return new EnigmossClient(
            new ClientConfig(
                baseUrl: $options['base_url'],
                projectId: $options['project_id'],
                paymentKey: self::secret($options['payment_key']),
                payoutKey: self::secret($options['payout_key']),
                timeout: $options['timeout'],
                connectTimeout: $options['connect_timeout'],
                maxRetries: $options['max_retries'],
                maxRetryDelay: $options['max_retry_delay'],
                webhookTolerance: $options['webhook_tolerance'],
                allowInsecureHttp: $options['allow_insecure_http'],
                fallbackBaseUrl: $fallback,
                originStore: $fallback === null || $originCache === null ? null : new CacheOriginStore($originCache),
            ),
            $httpClient === null ? null : new SymfonyHttpClientTransport($httpClient),
            observers: $logger === null ? [] : [new LoggerObserver($logger)],
        );
    }

    public static function verifier(EnigmossClient $client): WebhookVerifier
    {
        return $client->webhooks->verifier();
    }

    private static function secret(?string $value): ?Secret
    {
        return $value === null || $value === '' ? null : new Secret($value);
    }
}
