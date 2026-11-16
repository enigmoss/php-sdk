<?php

declare(strict_types=1);

namespace Enigmoss\Http;

use Enigmoss\Exception\ConfigurationException;
use Enigmoss\Exception\ConnectionException;
use Enigmoss\Exception\SendPhase;
use Enigmoss\Exception\TransportException;

/**
 * The default transport, built on ext-curl.
 *
 * Every request gets its own curl handle, so a shared client is safe under coroutine runtimes
 * that hook curl; connections and DNS lookups are reused through a curl share handle.
 * Redirects are never followed, TLS peers are always verified.
 *
 * It is the only bundled transport that can start the API-origin fallback
 * (ClientConfig::$fallbackBaseUrl): curl tells a connection that was never established (DNS,
 * refused connection, TLS handshake, connect timeout) from a failure after the request was sent.
 * Behind an outgoing proxy only a failed DNS lookup of the API host counts.
 */
final class CurlTransport implements TransportInterface
{
    /** curl errors raised before a single byte of the request was sent. */
    private const NOT_SENT_ERRORS = [
        5,  // CURLE_COULDNT_RESOLVE_PROXY
        6,  // CURLE_COULDNT_RESOLVE_HOST
        7,  // CURLE_COULDNT_CONNECT
        35, // CURLE_SSL_CONNECT_ERROR
        51, // CURLE_PEER_FAILED_VERIFICATION (old libcurl)
        58, // CURLE_SSL_CERTPROBLEM
        59, // CURLE_SSL_CIPHER
        60, // CURLE_PEER_FAILED_VERIFICATION / CURLE_SSL_CACERT
        77, // CURLE_SSL_CACERT_BADFILE
    ];

    private const TIMEOUT_ERROR = 28;

    private const RESOLVE_HOST_ERROR = 6;

    private const RESOLVE_PROXY_ERROR = 5;

    /** libcurl reads these when CURLOPT_PROXY is not set ("http_proxy" only in lower case). */
    private const PROXY_VARIABLES = ['https_proxy', 'HTTPS_PROXY', 'http_proxy', 'all_proxy', 'ALL_PROXY'];

    private ?\CurlShareHandle $share = null;

    private ?int $sharePid = null;

    /**
     * @param non-empty-string|null $caBundle Path to a CA bundle; the system store is used when null.
     * @param non-empty-string|null $proxy    Outgoing proxy URL, e.g. "http://proxy.local:3128".
     */
    public function __construct(
        private readonly ?string $caBundle = null,
        private readonly ?string $proxy = null,
    ) {
        if (!\extension_loaded('curl')) {
            throw new ConfigurationException(
                'ext-curl is not installed. Install it, or pass a PSR-18 client through Enigmoss\Bridge\Psr18\Psr18Transport.',
            );
        }
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $handle = curl_init();
        if ($handle === false) {
            throw new ConnectionException('curl_init() failed.');
        }

        $headers = [];
        $headerLines = ['Expect:'];
        foreach ($request->headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $options = [
            CURLOPT_URL => $request->url,
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_CONNECTTIMEOUT_MS => (int) ceil($request->connectTimeout * 1000),
            CURLOPT_TIMEOUT_MS => (int) ceil($request->timeout * 1000),
            CURLOPT_HEADERFUNCTION => static function (\CurlHandle $curl, string $line) use (&$headers): int {
                $trimmed = trim($line);
                if (str_starts_with($trimmed, 'HTTP/')) {
                    $headers = []; // a new response block (e.g. after 100 Continue)
                } elseif ($trimmed !== '' && str_contains($trimmed, ':')) {
                    [$name, $value] = explode(':', $trimmed, 2);
                    $headers[strtolower(trim($name))][] = trim($value);
                }

                return \strlen($line);
            },
        ];
        if ($request->body !== null) {
            $options[CURLOPT_POSTFIELDS] = $request->body;
        } elseif ($request->method === 'GET') {
            $options[CURLOPT_HTTPGET] = true;
        }
        if ($this->caBundle !== null) {
            $options[CURLOPT_CAINFO] = $this->caBundle;
        }
        if ($this->proxy !== null) {
            $options[CURLOPT_PROXY] = $this->proxy;
        }
        $options[CURLOPT_SHARE] = $this->share();

        curl_setopt_array($handle, $options);
        $body = curl_exec($handle);

        if (!\is_string($body)) {
            $errno = curl_errno($handle);
            $error = curl_error($handle);
            $preTransfer = curl_getinfo($handle, CURLINFO_PRETRANSFER_TIME);
            $requestSize = curl_getinfo($handle, CURLINFO_REQUEST_SIZE);

            throw $this->failure($errno, $error, $preTransfer, $requestSize);
        }

        return new HttpResponse(curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $headers, $body);
    }

    private function failure(int $errno, string $error, float $preTransferTime, int $requestSize): TransportException
    {
        $message = \sprintf('HTTP request failed: curl error %d: %s', $errno, $error);
        $notSent = \in_array($errno, self::NOT_SENT_ERRORS, true)
            || ($errno === self::TIMEOUT_ERROR && $preTransferTime <= 0.0 && $requestSize === 0);

        if ($notSent) {
            return new ConnectionException($message, null, $this->hostUnreachable($errno));
        }

        return new TransportException($message, SendPhase::Sent, $errno === self::TIMEOUT_ERROR);
    }

    /**
     * True when the failure proves that the API host itself could not be reached. Behind a proxy
     * a refused connection, a connect timeout or a TLS error may be the proxy's own, so only a
     * failure to resolve the API host counts there.
     */
    private function hostUnreachable(int $errno): bool
    {
        if ($errno === self::RESOLVE_PROXY_ERROR) {
            return false;
        }

        return $errno === self::RESOLVE_HOST_ERROR || !$this->mayUseProxy();
    }

    private function mayUseProxy(): bool
    {
        if ($this->proxy !== null) {
            return true;
        }
        foreach (self::PROXY_VARIABLES as $name) {
            $value = getenv($name);
            if (\is_string($value) && $value !== '') {
                return true;
            }
        }

        return false;
    }

    private function share(): \CurlShareHandle
    {
        $pid = getmypid();
        if ($this->share !== null && $this->sharePid === $pid) {
            return $this->share;
        }
        // A share handle must not cross a fork: recreate it in the child process.
        $share = curl_share_init();
        curl_share_setopt($share, CURLSHOPT_SHARE, CURL_LOCK_DATA_DNS);
        if (\defined('CURL_LOCK_DATA_CONNECT')) {
            curl_share_setopt($share, CURLSHOPT_SHARE, CURL_LOCK_DATA_CONNECT);
        }
        $this->share = $share;
        $this->sharePid = $pid === false ? null : $pid;

        return $share;
    }
}
