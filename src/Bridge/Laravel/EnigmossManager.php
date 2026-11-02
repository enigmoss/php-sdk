<?php

declare(strict_types=1);

namespace Enigmoss\Bridge\Laravel;

use Enigmoss\Balance\BalanceService;
use Enigmoss\Bridge\Psr3\LoggerObserver;
use Enigmoss\ClientConfig;
use Enigmoss\EnigmossClient;
use Enigmoss\Event\EventService;
use Enigmoss\Exception\ConfigurationException;
use Enigmoss\ExchangeRate\ExchangeRateService;
use Enigmoss\Payment\PaymentService;
use Enigmoss\PaymentMethod\PaymentMethodService;
use Enigmoss\Payout\PayoutService;
use Enigmoss\PayoutMethod\PayoutMethodService;
use Enigmoss\Project\ProjectService;
use Enigmoss\Refund\RefundService;
use Enigmoss\Secret;
use Enigmoss\Testing\Fixtures;
use Enigmoss\Testing\MockTransport;
use Enigmoss\Transaction\TransactionService;
use Enigmoss\Transfer\TransferService;
use Enigmoss\Wallet\WalletService;
use Enigmoss\Webhook\WebhookService;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Log\LogManager;

/**
 * Builds and caches one client per configured connection. Clients are stateless, so caching
 * them is safe under Octane.
 */
final class EnigmossManager
{
    /** @var array<string, EnigmossClient> */
    private array $clients = [];

    private ?MockTransport $fake = null;

    public function __construct(
        private readonly Config $config,
        private readonly ?LogManager $log = null,
    ) {}

    public function connection(?string $name = null): EnigmossClient
    {
        $name ??= $this->defaultConnection();

        return $this->clients[$name] ??= $this->make($name);
    }

    public function client(): EnigmossClient
    {
        return $this->connection();
    }

    /**
     * Replaces the HTTP transport of every connection with a MockTransport and returns it.
     */
    public function fake(): MockTransport
    {
        $this->clients = [];

        return $this->fake = new MockTransport();
    }

    public function defaultConnection(): string
    {
        $name = $this->config->get('enigmoss.default', 'default');

        return \is_string($name) ? $name : 'default';
    }

    public function project(): ProjectService
    {
        return $this->connection()->project;
    }

    public function payments(): PaymentService
    {
        return $this->connection()->payments;
    }

    public function wallets(): WalletService
    {
        return $this->connection()->wallets;
    }

    public function refunds(): RefundService
    {
        return $this->connection()->refunds;
    }

    public function payouts(): PayoutService
    {
        return $this->connection()->payouts;
    }

    public function payoutMethods(): PayoutMethodService
    {
        return $this->connection()->payoutMethods;
    }

    public function paymentMethods(): PaymentMethodService
    {
        return $this->connection()->paymentMethods;
    }

    public function exchangeRates(): ExchangeRateService
    {
        return $this->connection()->exchangeRates;
    }

    public function balances(): BalanceService
    {
        return $this->connection()->balances;
    }

    public function transactions(): TransactionService
    {
        return $this->connection()->transactions;
    }

    public function transfers(): TransferService
    {
        return $this->connection()->transfers;
    }

    public function events(): EventService
    {
        return $this->connection()->events;
    }

    public function webhooks(): WebhookService
    {
        return $this->connection()->webhooks;
    }

    private function make(string $name): EnigmossClient
    {
        $connection = $this->config->get('enigmoss.connections.' . $name);
        if ($this->fake !== null) {
            // Under fake() nothing reaches the network: fill what the tests did not configure.
            $connection = (\is_array($connection) ? array_filter($connection, static fn(mixed $v): bool => $v !== null && $v !== '') : []) + [
                'base_url' => 'https://api.example.com',
                'project_id' => Fixtures::PROJECT_ID,
                'payment_key' => 'test-payment-key',
                'payout_key' => 'test-payout-key',
            ];
        }
        if (!\is_array($connection)) {
            throw new ConfigurationException(\sprintf('Enigmoss connection "%s" is not configured in config/enigmoss.php.', $name));
        }

        $observers = [];
        $channel = $this->config->get('enigmoss.log_channel');
        if (\is_string($channel) && $channel !== '' && $this->log !== null) {
            $observers[] = new LoggerObserver($this->log->channel($channel));
        }

        return new EnigmossClient(
            new ClientConfig(
                baseUrl: self::string($connection, 'base_url', $name),
                projectId: self::string($connection, 'project_id', $name),
                paymentKey: self::secret($connection['payment_key'] ?? null),
                payoutKey: self::secret($connection['payout_key'] ?? null),
                timeout: (float) $this->number('enigmoss.timeout', 30),
                connectTimeout: (float) $this->number('enigmoss.connect_timeout', 10),
                maxRetries: (int) $this->number('enigmoss.max_retries', 2),
                maxRetryDelay: (float) $this->number('enigmoss.max_retry_delay', 10),
                webhookTolerance: (int) $this->number('enigmoss.webhooks.tolerance', 300),
                allowInsecureHttp: $this->config->get('enigmoss.allow_insecure_http') === true,
            ),
            $this->fake,
            observers: $observers,
        );
    }

    private function number(string $key, int|float $default): int|float
    {
        $value = $this->config->get($key, $default);

        return \is_int($value) || \is_float($value) ? $value : (is_numeric($value) ? +$value : $default);
    }

    /**
     * @param array<array-key, mixed> $connection
     */
    private static function string(array $connection, string $key, string $name): string
    {
        $value = $connection[$key] ?? null;
        if (!\is_string($value) || $value === '') {
            throw new ConfigurationException(\sprintf('Set "%s" of the Enigmoss connection "%s" (ENIGMOSS_%s).', $key, $name, strtoupper($key)));
        }

        return $value;
    }

    private static function secret(mixed $value): ?Secret
    {
        return \is_string($value) && $value !== '' ? new Secret($value) : null;
    }
}
