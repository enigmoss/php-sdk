<?php

declare(strict_types=1);

namespace Enigmoss\Bridge\Laravel\Http;

use Enigmoss\Bridge\Laravel\EnigmossManager;
use Enigmoss\Exception\WebhookVerificationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware "enigmoss.webhook[:connection]": verifies the signature of an incoming
 * webhook and stores the typed event in the "enigmoss_event" request attribute.
 */
final class VerifyEnigmossWebhook
{
    public const EVENT_ATTRIBUTE = 'enigmoss_event';

    public function __construct(private readonly EnigmossManager $manager) {}

    public function handle(Request $request, \Closure $next, ?string $connection = null): Response
    {
        try {
            $event = $this->manager->connection($connection)->webhooks->constructEvent(
                $request->getContent(),
                $request->headers->all(),
            );
        } catch (WebhookVerificationException $e) {
            return new \Illuminate\Http\JsonResponse(['error' => 'invalid_webhook', 'reason' => $e->reason->value], 400);
        }

        $request->attributes->set(self::EVENT_ATTRIBUTE, $event);
        $request->attributes->set(self::EVENT_ATTRIBUTE . '_connection', $connection ?? $this->manager->defaultConnection());

        /** @var Response */
        return $next($request);
    }
}
