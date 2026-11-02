<?php

declare(strict_types=1);

namespace Enigmoss\Bridge\Symfony\Console;

use Enigmoss\EnigmossClient;
use Enigmoss\Exception\EnigmossException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ServiceLocator;

#[AsCommand(name: 'enigmoss:check', description: 'Check Enigmoss API credentials, key permissions and clock drift')]
final class CheckCommand extends Command
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
        $this->addOption('client', null, InputOption::VALUE_REQUIRED, 'Client name from the bundle configuration');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $client = $this->client($input);
        $config = $client->config();
        $ok = true;

        foreach (array_filter(['payment' => $config->paymentKey, 'payout' => $config->payoutKey]) as $type => $key) {
            $single = $type === 'payment'
                ? $client->withCredentials($config->projectId, paymentKey: $key)
                : $client->withCredentials($config->projectId, payoutKey: $key);
            try {
                $project = $single->project->get();
                $io->success(\sprintf('%s key: project "%s" (%s)', ucfirst($type), $project->name, $project->id));
                $io->listing($project->permissions === [] ? ['(no permissions)'] : $project->permissions);
                $drift = $project->serverTime - time();
                if (abs($drift) > 30) {
                    $io->warning(\sprintf('Clock drift %+d s: sync the server clock (NTP).', $drift));
                }
            } catch (EnigmossException $e) {
                $ok = false;
                $io->error(\sprintf('%s key: %s', ucfirst($type), $e->getMessage()));
            }
        }

        return $ok ? Command::SUCCESS : Command::FAILURE;
    }
}
