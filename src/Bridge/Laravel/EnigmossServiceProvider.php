<?php

declare(strict_types=1);

namespace Enigmoss\Bridge\Laravel;

use Enigmoss\Bridge\Laravel\Console\CheckCommand;
use Enigmoss\Bridge\Laravel\Console\WebhookTestCommand;
use Enigmoss\Bridge\Laravel\Http\VerifyEnigmossWebhook;
use Enigmoss\Bridge\Laravel\Http\WebhookController;
use Enigmoss\EnigmossClient;
use Enigmoss\Webhook\WebhookDeduplicatorInterface;
use Enigmoss\Webhook\WebhookVerifier;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Log\LogManager;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Auto-discovered Laravel integration (Laravel 10+).
 *
 * Only lazy bindings are registered: package:discover and config:cache work without ENIGMOSS_*
 * variables; a missing variable fails when the client is first used.
 */
final class EnigmossServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (!self::supported($this->app)) {
            return;
        }

        $this->mergeConfigFrom(self::configPath(), 'enigmoss');

        $this->app->singleton(EnigmossManager::class, static fn(Application $app): EnigmossManager => new EnigmossManager(
            $app->make(ConfigRepository::class),
            $app->bound('log') ? $app->make(LogManager::class) : null,
            $app->bound('cache') ? $app->make(CacheRepository::class) : null,
        ));
        $this->app->bind(EnigmossClient::class, static fn(Application $app): EnigmossClient => $app->make(EnigmossManager::class)->connection());
        $this->app->bind(WebhookVerifier::class, static fn(Application $app): WebhookVerifier => $app->make(EnigmossClient::class)->webhooks->verifier());
        $this->app->bind(WebhookDeduplicatorInterface::class, static function (Application $app): WebhookDeduplicatorInterface {
            $ttl = $app->make(ConfigRepository::class)->get('enigmoss.webhooks.dedupe_ttl', 259200);

            return new CacheWebhookDeduplicator($app->make(CacheRepository::class), \is_int($ttl) ? $ttl : 259200);
        });
    }

    public function boot(): void
    {
        if (!self::supported($this->app)) {
            return;
        }

        $this->publishes([self::configPath() => $this->app->configPath('enigmoss.php')], 'enigmoss-config');

        if ($this->app->runningInConsole()) {
            $this->commands([CheckCommand::class, WebhookTestCommand::class]);
        }

        $this->callAfterResolving('router', static function (Router $router): void {
            $router->aliasMiddleware('enigmoss.webhook', VerifyEnigmossWebhook::class);
        });

        if (!Route::hasMacro('enigmossWebhooks')) {
            Route::macro('enigmossWebhooks', static function (string $uri = 'webhooks/enigmoss', ?string $connection = null) {
                return Route::post($uri, WebhookController::class)
                    ->middleware($connection === null ? 'enigmoss.webhook' : 'enigmoss.webhook:' . $connection)
                    ->name($connection === null ? 'enigmoss.webhooks' : 'enigmoss.webhooks.' . $connection);
            });
        }
    }

    private static function configPath(): string
    {
        return \dirname(__DIR__, 3) . '/config/enigmoss.php';
    }

    private static function supported(Application $app): bool
    {
        return version_compare($app->version(), '10.0', '>=');
    }
}
