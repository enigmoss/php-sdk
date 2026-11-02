<?php

declare(strict_types=1);

namespace Enigmoss\Bridge\Symfony\Console;

use Enigmoss\EnigmossClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;

#[AsCommand(name: 'enigmoss:webhook-test', description: 'Send a test webhook from the Enigmoss API to your endpoint')]
final class WebhookTestCommand extends Command
{
    /**
     * @param ServiceLocator<EnigmossClient> $clients Clients by name.
     */
    public function __construct(
        private readonly ServiceLocator $clients,
        private readonly string $defaultClient,
    ) {
        parent::__construct();
    }

    private function client(InputInterface $input): EnigmossClient
    {
        $name = $input->getOption('client');
        $name = \is_string($name) ? $name : $this->defaultClient;
        if (!$this->clients->has($name)) {
            throw new \InvalidArgumentException(\sprintf('Enigmoss client "%s" is not configured.', $name));
        }

        return $this->clients->get($name);
    }

    protected function configure(): void
    {
        $this->addOption('url', null, InputOption::VALUE_REQUIRED, 'Callback URL; the project default when omitted');
        $this->addOption('client', null, InputOption::VALUE_REQUIRED, 'Client name from the bundle configuration');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $url = $input->getOption('url');
        $record = $this->client($input)->webhooks->sendTest(\is_string($url) ? $url : null);
        $attempt = $record->attempts[\count($record->attempts) - 1] ?? null;

        $output->writeln(\sprintf('Event %s: %s', $record->id, $record->deliveryStatus->value));
        if ($attempt !== null) {
            $output->writeln(\sprintf('  %s -> HTTP %s', $attempt->url ?? '(no URL)', $attempt->responseStatusCode ?? '-'));
        }

        return Command::SUCCESS;
    }
}
