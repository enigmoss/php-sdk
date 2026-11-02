<?php

declare(strict_types=1);

namespace Enigmoss\Bridge\Laravel\Facades;

use Enigmoss\Bridge\Laravel\EnigmossManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Enigmoss\EnigmossClient connection(?string $name = null)
 * @method static \Enigmoss\EnigmossClient client()
 * @method static \Enigmoss\Testing\MockTransport fake()
 * @method static \Enigmoss\Project\ProjectService project()
 * @method static \Enigmoss\Payment\PaymentService payments()
 * @method static \Enigmoss\Wallet\WalletService wallets()
 * @method static \Enigmoss\Refund\RefundService refunds()
 * @method static \Enigmoss\Payout\PayoutService payouts()
 * @method static \Enigmoss\PayoutMethod\PayoutMethodService payoutMethods()
 * @method static \Enigmoss\PaymentMethod\PaymentMethodService paymentMethods()
 * @method static \Enigmoss\ExchangeRate\ExchangeRateService exchangeRates()
 * @method static \Enigmoss\Balance\BalanceService balances()
 * @method static \Enigmoss\Transaction\TransactionService transactions()
 * @method static \Enigmoss\Transfer\TransferService transfers()
 * @method static \Enigmoss\Event\EventService events()
 * @method static \Enigmoss\Webhook\WebhookService webhooks()
 *
 * @see EnigmossManager
 */
final class Enigmoss extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return EnigmossManager::class;
    }
}
