<?php

declare(strict_types=1);

namespace Enigmoss\Bridge\Symfony\Webhook;

/**
 * Injects a verified webhook event into a controller argument:
 *
 *     #[Route('/webhooks/enigmoss', methods: ['POST'])]
 *     public function __invoke(#[MapEnigmossWebhook] WebhookEvent $event): Response
 *
 * An invalid webhook is answered with 400 before the controller runs.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class MapEnigmossWebhook
{
    /**
     * @param string|null $client Client name from the bundle configuration; the default client when null.
     */
    public function __construct(public readonly ?string $client = null) {}
}
