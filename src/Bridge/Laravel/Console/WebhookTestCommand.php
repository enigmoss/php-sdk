<?php

declare(strict_types=1);

namespace Enigmoss\Bridge\Laravel\Console;

use Enigmoss\Bridge\Laravel\EnigmossManager;
use Illuminate\Console\Command;

/**
 * php artisan enigmoss:webhook-test - asks the API to send a webhook.test event.
 */
final class WebhookTestCommand extends Command
{
    /** @var string */
    protected $signature = 'enigmoss:webhook-test {--url= : Callback URL; the project default when omitted} {--connection=}';

    /** @var string */
    protected $description = 'Send a test webhook from the Enigmoss API to your endpoint';

    public function handle(EnigmossManager $manager): int
    {
        $connection = $this->option('connection');
        $url = $this->option('url');
        $record = $manager->connection(\is_string($connection) ? $connection : null)->webhooks->sendTest(\is_string($url) ? $url : null);

        $attempt = $record->attempts[\count($record->attempts) - 1] ?? null;
        $this->info(\sprintf('Event %s: %s', $record->id, $record->deliveryStatus->value));
        if ($attempt !== null) {
            $this->line(\sprintf('  %s -> HTTP %s', $attempt->url ?? '(no URL)', $attempt->responseStatusCode ?? '-'));
        }

        return self::SUCCESS;
    }
}
