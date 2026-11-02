<?php

declare(strict_types=1);

namespace Enigmoss\Tests\Support;

/**
 * The published signature test vectors (tests/Fixtures/contract/signature-vectors.json).
 *
 * The file is copied from the API contract with bin/sync-contract and never edited by hand:
 * the SDK must produce exactly these signatures.
 *
 * @phpstan-type RequestVector array{name: string, description: string, method: string, path: string, body: string, body_bytes: int, key_type: string, string_to_sign: string, signature: string}
 * @phpstan-type WebhookVector array{name: string, timestamp: string, project: string, secret: string, body: string, string_to_sign: string, signature: string}
 * @phpstan-type Document array{
 *     version: int,
 *     request: array{timestamp: string, project: string, secrets: array<string, string>, vectors: list<RequestVector>},
 *     webhook: array{vectors: list<WebhookVector>}
 * }
 */
final class SignatureVectors
{
    public const SUPPORTED_VERSION = 1;

    private const PATH = __DIR__ . '/../Fixtures/contract/signature-vectors.json';

    /** @var Document|null */
    private static ?array $document = null;

    /**
     * @return Document
     */
    public static function document(): array
    {
        if (self::$document !== null) {
            return self::$document;
        }

        /** @var Document $document The shape is checked by the tests that read it. */
        $document = json_decode((string) file_get_contents(self::PATH), true, 512, \JSON_THROW_ON_ERROR);

        return self::$document = $document;
    }

    public static function timestamp(): int
    {
        return (int) self::document()['request']['timestamp'];
    }

    public static function project(): string
    {
        return self::document()['request']['project'];
    }

    public static function secret(string $keyType): string
    {
        return self::document()['request']['secrets'][$keyType] ?? throw new \RuntimeException("Unknown key type {$keyType}");
    }

    /**
     * @return array<string, RequestVector>
     */
    public static function requests(): array
    {
        $vectors = [];
        foreach (self::document()['request']['vectors'] as $vector) {
            $vectors[$vector['name']] = $vector;
        }

        return $vectors;
    }

    /**
     * @return RequestVector
     */
    public static function request(string $name): array
    {
        return self::requests()[$name] ?? throw new \RuntimeException("Unknown signature vector {$name}");
    }

    /**
     * @return WebhookVector
     */
    public static function webhook(): array
    {
        return self::document()['webhook']['vectors'][0] ?? throw new \RuntimeException('No webhook signature vector');
    }
}
