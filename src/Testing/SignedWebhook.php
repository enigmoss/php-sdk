<?php

declare(strict_types=1);

namespace Enigmoss\Testing;

/**
 * A webhook request body with valid signature headers.
 */
final class SignedWebhook
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly string $body,
        public readonly array $headers,
    ) {}

    /**
     * The headers as PHP server variables (HTTP_X_SIGNATURE...), for framework test clients:
     *
     *     $this->call('POST', '/webhooks/enigmoss', [], [], [], $webhook->serverVariables(), $webhook->body); // Laravel
     *     $client->request('POST', '/webhooks/enigmoss', [], [], $webhook->serverVariables(), $webhook->body);  // Symfony
     *
     * @return array<string, string>
     */
    public function serverVariables(): array
    {
        $server = [];
        foreach ($this->headers as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            $server[$key === 'CONTENT_TYPE' ? $key : 'HTTP_' . $key] = $value;
        }

        return $server;
    }
}
