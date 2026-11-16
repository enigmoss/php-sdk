<?php

declare(strict_types=1);

namespace Enigmoss;

use Enigmoss\Auth\KeyScope;
use Enigmoss\Balance\BalanceService;
use Enigmoss\Event\EventService;
use Enigmoss\ExchangeRate\ExchangeRateService;
use Enigmoss\Http\CurlTransport;
use Enigmoss\Http\ObserverInterface;
use Enigmoss\Http\RawResponse;
use Enigmoss\Http\TransportInterface;
use Enigmoss\Internal\ClockSkew;
use Enigmoss\Internal\Endpoint;
use Enigmoss\Internal\IdempotencyMode;
use Enigmoss\Internal\Requester;
use Enigmoss\Internal\RetryStrategy;
use Enigmoss\Payment\PaymentService;
use Enigmoss\PaymentMethod\PaymentMethodService;
use Enigmoss\Payout\PayoutService;
use Enigmoss\PayoutMethod\PayoutMethodService;
use Enigmoss\Project\ProjectService;
use Enigmoss\Refund\RefundService;
use Enigmoss\Retry\ClockInterface;
use Enigmoss\Retry\RetryClass;
use Enigmoss\Retry\SleeperInterface;
use Enigmoss\Retry\SystemClock;
use Enigmoss\Retry\SystemSleeper;
use Enigmoss\Transaction\TransactionService;
use Enigmoss\Transfer\TransferService;
use Enigmoss\Wallet\WalletService;
use Enigmoss\Webhook\WebhookService;

/**
 * Entry point of the SDK.
 *
 *     $enigmoss = new EnigmossClient(new ClientConfig(
 *         baseUrl: 'https://api.example.com',
 *         projectId: '3f2b8c1e-5d4a-4e7b-9c6f-1a2b3c4d5e6f',
 *         paymentKey: new Secret(getenv('ENIGMOSS_PAYMENT_KEY')),
 *     ));
 *     $project = $enigmoss->project->get();
 *
 * The client is stateless apart from a clock-drift correction and, when a fallback base URL is
 * configured, the API origin learned from the fallback host. One instance can be shared by the
 * whole application, including long-running workers.
 */
final class EnigmossClient
{
    /** The project of the key: credentials check, permissions, server time. */
    public readonly ProjectService $project;

    /** Invoices (payment key). */
    public readonly PaymentService $payments;

    /** Static wallets (payment key). */
    public readonly WalletService $wallets;

    /** Refunds of payments and blocked wallets (payout key). */
    public readonly RefundService $refunds;

    /** Payouts and payout quotes (payout key). */
    public readonly PayoutService $payouts;

    /** Currencies and networks available for payouts (either key). */
    public readonly PayoutMethodService $payoutMethods;

    /** Payment methods of your tariff and their discounts. */
    public readonly PaymentMethodService $paymentMethods;

    /** Exchange rates (either key). */
    public readonly ExchangeRateService $exchangeRates;

    /** Business balances (payment key). */
    public readonly BalanceService $balances;

    /** Balance movements (payment key). */
    public readonly TransactionService $transactions;

    /** Transfers to the personal account (payout key). */
    public readonly TransferService $transfers;

    /** Webhook events journal and redelivery (payment key). */
    public readonly EventService $events;

    /** Incoming webhook verification and test webhooks. */
    public readonly WebhookService $webhooks;

    private readonly Requester $requester;

    private readonly TransportInterface $transport;

    private readonly ClockInterface $clock;

    private readonly SleeperInterface $sleeper;

    /**
     * @param TransportInterface|null $transport Defaults to the built-in CurlTransport.
     * @param ClockInterface|null     $clock     Injectable for tests.
     * @param SleeperInterface|null   $sleeper   Injectable for tests (retries sleep through it).
     * @param list<ObserverInterface> $observers Receive request, response, retry and failure events.
     */
    public function __construct(
        ClientConfig $config,
        ?TransportInterface $transport = null,
        ?ClockInterface $clock = null,
        ?SleeperInterface $sleeper = null,
        private readonly array $observers = [],
    ) {
        $this->transport = $transport ?? new CurlTransport();
        $this->clock = $clock ?? new SystemClock();
        $this->sleeper = $sleeper ?? new SystemSleeper();
        $this->requester = new Requester(
            $config,
            $this->transport,
            new RetryStrategy(),
            new ClockSkew(),
            $this->clock,
            $this->sleeper,
            $this->observers,
        );
        $this->project = new ProjectService($this->requester);
        $this->payments = new PaymentService($this->requester);
        $this->wallets = new WalletService($this->requester);
        $this->refunds = new RefundService($this->requester);
        $this->payouts = new PayoutService($this->requester);
        $this->payoutMethods = new PayoutMethodService($this->requester);
        $this->paymentMethods = new PaymentMethodService($this->requester);
        $this->exchangeRates = new ExchangeRateService($this->requester);
        $this->balances = new BalanceService($this->requester);
        $this->transactions = new TransactionService($this->requester);
        $this->transfers = new TransferService($this->requester);
        $this->events = new EventService($this->requester);
        $this->webhooks = new WebhookService($this->requester, $this->clock);
    }

    /**
     * A client for another project that shares this client's transport and observers.
     */
    public function withCredentials(string $projectId, ?Secret $paymentKey = null, ?Secret $payoutKey = null): self
    {
        return new self(
            $this->requester->config()->withCredentials($projectId, $paymentKey, $payoutKey),
            $this->transport,
            $this->clock,
            $this->sleeper,
            $this->observers,
        );
    }

    /**
     * The configuration of this client. $origin is the API origin in use right now: the
     * configured base URL, or the origin the fallback host named after the configured one could
     * not be reached (see ClientConfig::$fallbackOrigin).
     */
    public function config(): ClientConfig
    {
        $config = $this->requester->config();
        $origin = $this->requester->origin();

        return $origin === $config->origin ? $config : $config->withOrigin($origin);
    }

    /**
     * Calls any v4 endpoint, including ones this SDK version does not model yet, with the same
     * signing, idempotency, retries and typed exceptions as the typed services.
     *
     *     $raw = $enigmoss->request('POST', '/payments/' . rawurlencode($id) . '/cancel', [], KeyScope::Payment, idempotent: true);
     *
     * @param string                              $path       Path after "/api/v4", starting with "/". Encode dynamic segments with rawurlencode().
     * @param array<string, mixed>|null           $body       JSON body for POST/PATCH; null sends "{}".
     * @param array<string, string|int|bool|null> $query
     * @param bool                                $idempotent Send an Idempotency-Key (a fresh one unless given in $options).
     *
     * @throws Exception\EnigmossException
     */
    public function request(
        string $method,
        string $path,
        ?array $body = null,
        KeyScope $scope = KeyScope::Payment,
        array $query = [],
        bool $idempotent = false,
        ?RequestOptions $options = null,
    ): RawResponse {
        $method = strtoupper($method);
        if ($method === '') {
            throw new Exception\InvalidArgumentException('method must not be empty.');
        }
        if (!str_starts_with($path, '/') || str_contains($path, '?') || str_contains($path, '{')) {
            throw new Exception\InvalidArgumentException('path must start with "/" and contain neither a query string nor "{".');
        }

        $retryClass = match (true) {
            $method === 'GET' => RetryClass::Read,
            $idempotent => RetryClass::MoneyCreate,
            default => RetryClass::NonIdempotent,
        };
        $endpoint = new Endpoint($method, $path, $scope, $retryClass, $idempotent ? IdempotencyMode::Supported : IdempotencyMode::None);
        $result = $this->requester->execute($endpoint, query: $query, body: $body, options: $options);

        return new RawResponse($result->data, $result->meta, $result->body, $result->info);
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new \LogicException('EnigmossClient cannot be serialized. Resolve it from your container inside the job instead of passing it to the queue.');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('EnigmossClient cannot be unserialized.');
    }

}
