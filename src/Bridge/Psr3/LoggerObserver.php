<?php

declare(strict_types=1);

namespace Enigmoss\Bridge\Psr3;

use Enigmoss\Http\ClientEvent;
use Enigmoss\Http\ClientEventType;
use Enigmoss\Http\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * Logs SDK calls to a PSR-3 logger: method, path, status, request id, duration, retries.
 * Bodies, headers and keys are never logged.
 */
final class LoggerObserver implements ObserverInterface
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public function onEvent(ClientEvent $event): void
    {
        $context = array_filter([
            'method' => $event->method,
            'path' => $event->path,
            'attempt' => $event->attempt,
            'status' => $event->statusCode,
            'request_id' => $event->requestId,
            'duration_ms' => $event->durationSeconds === null ? null : (int) round($event->durationSeconds * 1000),
            'retry_in_s' => $event->retryDelaySeconds,
            'error' => $event->error === null ? null : $event->error::class . ': ' . $event->error->getMessage(),
        ], static fn(mixed $value): bool => $value !== null);

        match ($event->type) {
            ClientEventType::RequestSending => null,
            ClientEventType::ResponseReceived => $this->logger->debug('Enigmoss API response', $context),
            ClientEventType::RetryScheduled => $this->logger->notice('Enigmoss API call retried', $context),
            ClientEventType::RequestFailed => $this->logger->warning('Enigmoss API call failed', $context),
        };
    }
}
