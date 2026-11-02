<?php

declare(strict_types=1);

namespace Enigmoss\Bridge\Laravel\Testing;

use Enigmoss\Bridge\Laravel\EnigmossManager;
use Enigmoss\Exception\ConfigurationException;
use Enigmoss\Testing\WebhookSimulator;
use Illuminate\Container\Container;
use Illuminate\Testing\TestResponse;

/**
 * Sends signed webhooks to your Laravel application in feature tests:
 *
 *     use InteractsWithEnigmossWebhooks;
 *
 *     $this->postEnigmossWebhook('payment.paid', Fixtures::paidPayment())->assertOk();
 *
 * The webhook is signed with the payment key of the connection, exactly as the API signs it.
 */
trait InteractsWithEnigmossWebhooks
{
    /**
     * @param array<string, mixed>|null $data The object snapshot (Fixtures::payment() etc.), null for webhook.test.
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function postEnigmossWebhook(
        string $event,
        ?array $data,
        string $uri = '/webhooks/enigmoss',
        ?string $connection = null,
        string $eventId = '019b7710-0000-7000-8000-000000000001',
    ): TestResponse {
        $config = Container::getInstance()->make(EnigmossManager::class)->connection($connection)->config();
        if ($config->paymentKey === null) {
            throw new ConfigurationException('Webhooks are signed with the payment key; configure payment_key for the connection.');
        }

        $webhook = WebhookSimulator::sign(
            ['event' => $event, 'event_id' => $eventId, 'created_at' => gmdate('Y-m-d\TH:i:s\Z'), 'data' => $data],
            $config->projectId,
            $config->paymentKey,
        );

        return $this->call('POST', $uri, [], [], [], $webhook->serverVariables(), $webhook->body);
    }
}
