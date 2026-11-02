<?php

declare(strict_types=1);

namespace Enigmoss;

use Enigmoss\Exception\ConfigurationException;

/**
 * Immutable client configuration.
 *
 * The base URL is mandatory: use the API host shown in your dashboard, for example
 * "https://api.example.com". The SDK appends "/api/v4" itself; a base URL that already ends
 * with "/api/v4" is accepted too. Any other path is rejected, because the server signs the exact
 * request path and a path-rewriting proxy would break every signature.
 *
 * At least one key is required. The payment key covers payments, static wallets, balances,
 * transactions and webhook events; the payout key covers payouts, refunds and transfers.
 * Pass only the keys you use.
 */
final class ClientConfig
{
    public const API_PREFIX = '/api/v4';

    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /**
     * Scheme, host and optional port, without a trailing slash.
     *
     * @var non-empty-string
     */
    public readonly string $origin;

    /** Project UUID in lower case, as sent in the X-Project-Uuid header. */
    public readonly string $projectId;

    /**
     * @param string      $baseUrl           API host, e.g. "https://api.example.com".
     * @param string      $projectId        Project UUID from the dashboard.
     * @param Secret|null $paymentKey        Payment API key.
     * @param Secret|null $payoutKey         Payout API key.
     * @param float       $timeout           Total time limit of one HTTP attempt, in seconds.
     * @param float       $connectTimeout    Time limit to establish a connection, in seconds.
     * @param int         $maxRetries        Automatic retries after the first attempt (0 disables retries).
     * @param float       $maxRetryDelay     The longest Retry-After the SDK waits for; a longer one is thrown to you.
     * @param int         $webhookTolerance  Accepted age of a webhook X-Timestamp, in seconds.
     * @param bool        $allowInsecureHttp Allow "http://" base URLs (local development only).
     * @param AppInfo|null $appInfo          Your application name, appended to the User-Agent.
     */
    public function __construct(
        string $baseUrl,
        string $projectId,
        public readonly ?Secret $paymentKey = null,
        public readonly ?Secret $payoutKey = null,
        public readonly float $timeout = 30.0,
        public readonly float $connectTimeout = 10.0,
        public readonly int $maxRetries = 2,
        public readonly float $maxRetryDelay = 10.0,
        public readonly int $webhookTolerance = 300,
        public readonly bool $allowInsecureHttp = false,
        public readonly ?AppInfo $appInfo = null,
    ) {
        $this->origin = self::normalizeBaseUrl($baseUrl, $allowInsecureHttp);
        $this->projectId = self::normalizeProjectId($projectId);

        if ($paymentKey === null && $payoutKey === null) {
            throw new ConfigurationException('Pass at least one API key: paymentKey and/or payoutKey.');
        }
        if ($timeout <= 0 || $connectTimeout <= 0) {
            throw new ConfigurationException('Timeouts must be positive numbers of seconds.');
        }
        if ($maxRetries < 0 || $maxRetries > 10) {
            throw new ConfigurationException('maxRetries must be between 0 and 10.');
        }
        if ($maxRetryDelay < 0) {
            throw new ConfigurationException('maxRetryDelay must not be negative.');
        }
        if ($webhookTolerance < 1) {
            throw new ConfigurationException('webhookTolerance must be at least one second.');
        }
    }

    /**
     * Returns a copy with other credentials, for platforms that serve several projects.
     */
    public function withCredentials(string $projectId, ?Secret $paymentKey = null, ?Secret $payoutKey = null): self
    {
        return new self(
            baseUrl: $this->origin,
            projectId: $projectId,
            paymentKey: $paymentKey,
            payoutKey: $payoutKey,
            timeout: $this->timeout,
            connectTimeout: $this->connectTimeout,
            maxRetries: $this->maxRetries,
            maxRetryDelay: $this->maxRetryDelay,
            webhookTolerance: $this->webhookTolerance,
            allowInsecureHttp: $this->allowInsecureHttp,
            appInfo: $this->appInfo,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'origin' => $this->origin,
            'projectId' => $this->projectId,
            'paymentKey' => $this->paymentKey === null ? null : '********',
            'payoutKey' => $this->payoutKey === null ? null : '********',
            'timeout' => $this->timeout,
            'connectTimeout' => $this->connectTimeout,
            'maxRetries' => $this->maxRetries,
            'maxRetryDelay' => $this->maxRetryDelay,
            'webhookTolerance' => $this->webhookTolerance,
            'allowInsecureHttp' => $this->allowInsecureHttp,
        ];
    }

    /**
     * @return non-empty-string
     */
    private static function normalizeBaseUrl(string $baseUrl, bool $allowInsecureHttp): string
    {
        $parts = parse_url(trim($baseUrl));
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            throw new ConfigurationException('baseUrl must be an absolute URL such as "https://api.example.com".');
        }

        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'https' && !($scheme === 'http' && $allowInsecureHttp)) {
            throw new ConfigurationException('baseUrl must use https (set allowInsecureHttp only for local development).');
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new ConfigurationException('baseUrl must not contain credentials, a query string or a fragment.');
        }

        $path = rtrim($parts['path'] ?? '', '/');
        if ($path !== '' && $path !== self::API_PREFIX) {
            throw new ConfigurationException(\sprintf(
                'baseUrl must point at the API host ("https://api.example.com"), optionally followed by "%s"; got path "%s".',
                self::API_PREFIX,
                $path,
            ));
        }

        $origin = $scheme . '://' . strtolower($parts['host']);
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        return $origin;
    }

    private static function normalizeProjectId(string $projectId): string
    {
        $normalized = strtolower(trim($projectId));
        if (preg_match(self::UUID_PATTERN, $normalized) !== 1) {
            throw new ConfigurationException('projectId must be the project UUID, e.g. "3f2b8c1e-5d4a-4e7b-9c6f-1a2b3c4d5e6f".');
        }

        return $normalized;
    }
}
