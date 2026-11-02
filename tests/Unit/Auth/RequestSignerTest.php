<?php

declare(strict_types=1);

namespace Enigmoss\Tests\Unit\Auth;

use Enigmoss\Auth\RequestSigner;
use Enigmoss\Secret;
use Enigmoss\Tests\Support\SignatureVectors;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The published signature test vectors of the Project API v4 (tests/Fixtures/contract/signature-vectors.json).
 */
final class RequestSignerTest extends TestCase
{
    public function testFixtureVersionIsSupported(): void
    {
        self::assertSame(SignatureVectors::SUPPORTED_VERSION, SignatureVectors::document()['version']);
        // Three request vectors at least: a silently emptied file would pass the provider with no cases.
        self::assertSame(['get-without-body', 'post-with-body-and-query', 'non-ascii-body-with-crlf'], array_keys(SignatureVectors::requests()));
    }

    /**
     * @return iterable<string, array{string, string, string, string, string, string}>
     */
    public static function vectors(): iterable
    {
        foreach (SignatureVectors::requests() as $name => $vector) {
            yield $name => [SignatureVectors::secret($vector['key_type']), $vector['method'], $vector['path'], $vector['body'], $vector['string_to_sign'], $vector['signature']];
        }
    }

    #[DataProvider('vectors')]
    public function testRequestSignatureMatchesServerVector(string $secret, string $method, string $uri, string $body, string $stringToSign, string $expected): void
    {
        self::assertSame($expected, RequestSigner::signRequest(new Secret($secret), SignatureVectors::timestamp(), SignatureVectors::project(), $method, $uri, $body));
        self::assertSame($expected, hash_hmac('sha256', $stringToSign, $secret));
    }

    public function testUnicodeVectorBodyIsSignedAsRawBytes(): void
    {
        $vector = SignatureVectors::request('non-ascii-body-with-crlf');

        self::assertSame($vector['body_bytes'], \strlen($vector['body']));
        self::assertStringContainsString("\r\n", $vector['body']);
    }

    public function testMethodIsUpperCased(): void
    {
        $vector = SignatureVectors::request('get-without-body');

        self::assertSame(
            $vector['signature'],
            RequestSigner::signRequest(new Secret(SignatureVectors::secret($vector['key_type'])), SignatureVectors::timestamp(), SignatureVectors::project(), strtolower($vector['method']), $vector['path'], $vector['body']),
        );
    }

    public function testWebhookSignatureMatchesServerVector(): void
    {
        $vector = SignatureVectors::webhook();

        self::assertSame(
            $vector['signature'],
            RequestSigner::signWebhook(new Secret($vector['secret']), $vector['timestamp'], $vector['project'], $vector['body']),
        );
    }
}
