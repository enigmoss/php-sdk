<?php

declare(strict_types=1);

namespace Enigmoss\Testing;

/**
 * Realistic API payloads in the wire format, for MockTransport responses and webhook tests.
 * Every factory accepts overrides merged recursively:
 *
 *     $transport->queueData(Fixtures::payment(['status' => 'paid', 'is_final' => true]));
 */
final class Fixtures
{
    public const PROJECT_ID = '3f2b8c1e-5d4a-4e7b-9c6f-1a2b3c4d5e6f';

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function project(array $overrides = []): array
    {
        return self::merge([
            'id' => self::PROJECT_ID,
            'name' => 'Example Shop',
            'key_type' => 'payment',
            'permissions' => ['payment_create_invoice', 'info_payments', 'webhooks'],
            'server_time' => 1767225600,
        ], $overrides);
    }

    /**
     * Response of project->platformBrand(). Pass ['brand' => null] for a project without a brand.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function platformBrand(array $overrides = []): array
    {
        return self::merge([
            'brand' => [
                'name' => 'Example Pay',
                'logo' => [
                    'url' => 'https://static.example.com/brand/logo.png',
                    'sha256' => 'b5bb9d8014a0f9b1d61e21e796d78dccdf1352f23cd32812f4850b878ae4944c',
                    'mime' => 'image/png',
                    'width' => 240,
                    'height' => 64,
                ],
            ],
            'api' => ['origin' => 'https://api.example.com'],
            'payform' => ['origin' => 'https://pay.example.com'],
            'refresh_after' => 86400,
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function payment(array $overrides = []): array
    {
        return self::merge([
            'id' => '7c9e6679-7425-40de-944b-e07fc1f90ae7',
            'order_id' => 'order-42',
            'wallet_id' => null,
            'status' => 'pending',
            'is_final' => false,
            'failure_code' => null,
            'amount' => '10.50',
            'currency_code' => 'USDT',
            'payer' => ['amount' => null, 'currency_code' => null, 'network_code' => null, 'address' => null, 'sender_address' => null, 'exchange_rate' => null],
            'paid' => ['amount' => null, 'amount_usd' => null],
            'settlement' => ['amount' => null, 'currency_code' => null, 'fee' => null],
            'conversion' => null,
            'discount' => ['percent' => null, 'amount' => null],
            'tx_hash' => null,
            'payment_url' => 'https://pay.example.com/invoice/7c9e6679-7425-40de-944b-e07fc1f90ae7?theme=system',
            'expires_at' => '2026-01-01T01:00:00Z',
            'created_at' => '2026-01-01T00:00:00Z',
            'updated_at' => '2026-01-01T00:00:00Z',
            'paid_at' => null,
            'description' => null,
            'metadata' => [],
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function paidPayment(array $overrides = []): array
    {
        return self::payment(self::merge([
            'status' => 'paid',
            'is_final' => true,
            'payer' => ['amount' => '10.50', 'currency_code' => 'USDT', 'network_code' => 'tron', 'address' => 'TXYZabcdefghijklmnopqrstuvwxyz1234', 'sender_address' => 'TSenderabcdefghijklmnopqrstuvwxyz12', 'exchange_rate' => '1'],
            'paid' => ['amount' => '10.50', 'amount_usd' => '10.49'],
            'settlement' => ['amount' => '10.29', 'currency_code' => 'USDT', 'fee' => '0.21'],
            'tx_hash' => '0x7f3a9c',
            'paid_at' => '2026-01-01T00:05:00Z',
        ], $overrides));
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function wallet(array $overrides = []): array
    {
        return self::merge([
            'id' => '0f8fad5b-d9cb-469f-a165-70867728950e',
            'order_id' => 'customer-7',
            'currency_code' => 'USDT',
            'network_code' => 'tron',
            'address' => 'TXYZabcdefghijklmnopqrstuvwxyz1234',
            'status' => 'active',
            'refund_incoming' => false,
            'callback_url' => null,
            'payment_url' => 'https://pay.example.com/static/0f8fad5b-d9cb-469f-a165-70867728950e',
            'created_at' => '2026-01-01T00:00:00Z',
            'updated_at' => '2026-01-01T00:00:00Z',
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function refund(array $overrides = []): array
    {
        return self::merge([
            'id' => '01970000-0000-7000-8000-000000000001',
            'order_id' => 'refund-42',
            'source' => 'payment',
            'payment_id' => '7c9e6679-7425-40de-944b-e07fc1f90ae7',
            'wallet_id' => null,
            'status' => 'pending',
            'is_final' => false,
            'failure_code' => null,
            'amount' => '10.50',
            'currency_code' => 'USDT',
            'network_code' => 'tron',
            'address' => 'TCustomerabcdefghijklmnopqrstuvwxy',
            'tx_hash' => null,
            'created_at' => '2026-01-02T00:00:00Z',
            'updated_at' => '2026-01-02T00:00:00Z',
            'completed_at' => null,
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function payout(array $overrides = []): array
    {
        return self::merge([
            'id' => '9b2d4f6a-1c3e-4a5b-8d7f-0e1a2b3c4d5e',
            'order_id' => 'withdrawal-42',
            'status' => 'pending',
            'is_final' => false,
            'failure_code' => null,
            'amount' => '100.00',
            'currency_code' => 'USDT',
            'network_code' => 'tron',
            'address' => 'TRecipientabcdefghijklmnopqrstuvwx',
            'memo' => null,
            'priority' => 'recommended',
            'recipient' => ['amount' => '99.00', 'currency_code' => 'USDT'],
            'charged' => ['amount' => '100.00', 'currency_code' => 'USDT', 'fee' => '1.00'],
            'conversion' => null,
            'tx_hash' => null,
            'created_at' => '2026-01-03T00:00:00Z',
            'updated_at' => '2026-01-03T00:00:00Z',
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function payoutQuote(array $overrides = []): array
    {
        return self::merge([
            'amount' => '100.00',
            'currency_code' => 'USDT',
            'network_code' => 'tron',
            'priority' => 'recommended',
            'recipient' => ['amount' => '99.00', 'currency_code' => 'USDT'],
            'charged' => ['amount' => '100.00', 'currency_code' => 'USDT', 'fee' => '1.00'],
            'conversion' => null,
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function transfer(array $overrides = []): array
    {
        return self::merge([
            'id' => '01970000-0000-7000-8000-000000000002',
            'order_id' => 'transfer-1',
            'amount' => '25.00',
            'currency_code' => 'USDT',
            'transaction_id' => 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d',
            'created_at' => '2026-01-04T00:00:00Z',
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function transaction(array $overrides = []): array
    {
        return self::merge([
            'id' => 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d',
            'type' => 'payment',
            'amount' => '10.29',
            'fee' => '0.21',
            'currency_code' => 'USDT',
            'network_code' => 'tron',
            'payment_id' => '7c9e6679-7425-40de-944b-e07fc1f90ae7',
            'payout_id' => null,
            'refund_id' => null,
            'transfer_id' => null,
            'created_at' => '2026-01-01T00:05:00Z',
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function balance(array $overrides = []): array
    {
        return self::merge(['currency_code' => 'USDT', 'balance' => '490.50', 'balance_usd' => '490.12'], $overrides);
    }

    /**
     * @param array<string, mixed>|null $data
     *
     * @return array<string, mixed> A webhook body envelope.
     */
    public static function webhook(string $event, ?array $data, string $eventId = '019b7710-0000-7000-8000-000000000001', string $createdAt = '2026-01-01T00:05:00Z'): array
    {
        return ['event' => $event, 'event_id' => $eventId, 'created_at' => $createdAt, 'data' => $data];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed> An events-journal record.
     */
    public static function eventRecord(array $overrides = []): array
    {
        $payload = self::webhook('payment.paid', self::paidPayment());

        return self::merge([
            'id' => $payload['event_id'],
            'event' => 'payment.paid',
            'object_type' => 'payment',
            'object_id' => '7c9e6679-7425-40de-944b-e07fc1f90ae7',
            'delivery_status' => 'delivered',
            'created_at' => '2026-01-01T00:05:00Z',
            'payload' => $payload,
        ], $overrides);
    }

    /**
     * @param array<array-key, mixed> $base
     * @param array<array-key, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function merge(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            $base[$key] = \is_array($value) && \is_array($base[$key] ?? null) && !array_is_list($value)
                ? self::merge($base[$key], $value)
                : $value;
        }

        /** @var array<string, mixed> $base Keys of the fixtures and overrides are field names. */
        return $base;
    }
}
