<?php

declare(strict_types=1);

namespace Enigmoss\Bridge\Symfony\Webhook;

use Enigmoss\Event\WebhookEvent;
use Enigmoss\Exception\WebhookVerificationException;
use Enigmoss\Webhook\WebhookVerifier;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * @internal
 */
final class WebhookValueResolver implements ValueResolverInterface
{
    /**
     * @param ServiceLocator<WebhookVerifier> $verifiers Verifiers by client name.
     */
    public function __construct(
        private readonly ServiceLocator $verifiers,
        private readonly string $defaultClient,
    ) {}

    /**
     * @return iterable<WebhookEvent>
     */
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        $attributes = $argument->getAttributesOfType(MapEnigmossWebhook::class);
        if ($attributes === []) {
            return [];
        }

        $client = $attributes[0]->client ?? $this->defaultClient;
        if (!$this->verifiers->has($client)) {
            throw new \LogicException(\sprintf('Enigmoss client "%s" is not configured.', $client));
        }

        try {
            return [$this->verifiers->get($client)->constructEvent($request->getContent(), $request->headers->all())];
        } catch (WebhookVerificationException $e) {
            throw new BadRequestHttpException('Invalid Enigmoss webhook: ' . $e->reason->value, $e);
        }
    }
}
