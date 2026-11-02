<?php

declare(strict_types=1);

namespace Enigmoss\Bridge\Laravel\Console;

use Enigmoss\Bridge\Laravel\EnigmossManager;
use Enigmoss\Exception\EnigmossException;
use Illuminate\Console\Command;

/**
 * php artisan enigmoss:check - verifies credentials, permissions and clock drift.
 */
final class CheckCommand extends Command
{
    /** @var string */
    protected $signature = 'enigmoss:check {--connection= : Connection from config/enigmoss.php}';

    /** @var string */
    protected $description = 'Check Enigmoss API credentials, key permissions and clock drift';

    public function handle(EnigmossManager $manager): int
    {
        $connection = $this->option('connection');
        $client = $manager->connection(\is_string($connection) ? $connection : null);
        $config = $client->config();

        $keys = array_filter(['payment' => $config->paymentKey, 'payout' => $config->payoutKey]);
        $ok = true;
        foreach ($keys as $type => $key) {
            $single = $type === 'payment'
                ? $client->withCredentials($config->projectId, paymentKey: $key)
                : $client->withCredentials($config->projectId, payoutKey: $key);
            try {
                $project = $single->project->get();
                $drift = $project->serverTime - time();
                $this->info(\sprintf('%s key: OK - project "%s" (%s)', ucfirst($type), $project->name, $project->id));
                $this->line('  permissions: ' . ($project->permissions === [] ? '(none)' : implode(', ', $project->permissions)));
                if (abs($drift) > 30) {
                    $this->warn(\sprintf('  clock drift: %+d s - sync the server clock (NTP); the SDK compensates, but signatures expire after 300 s.', $drift));
                }
            } catch (EnigmossException $e) {
                $ok = false;
                $this->error(\sprintf('%s key: FAILED - %s', ucfirst($type), $e->getMessage()));
            }
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
