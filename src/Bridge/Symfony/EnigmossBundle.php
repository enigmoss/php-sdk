<?php

declare(strict_types=1);

namespace Enigmoss\Bridge\Symfony;

use Enigmoss\Bridge\Symfony\Console\CheckCommand;
use Enigmoss\Bridge\Symfony\Console\WebhookTestCommand;
use Enigmoss\Bridge\Symfony\Webhook\CacheWebhookDeduplicator;
use Enigmoss\Bridge\Symfony\Webhook\WebhookValueResolver;
use Enigmoss\EnigmossClient;
use Enigmoss\Webhook\WebhookDeduplicatorInterface;
use Enigmoss\Webhook\WebhookVerifier;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Symfony integration (6.4+). Register in config/bundles.php:
 *
 *     Enigmoss\Bridge\Symfony\EnigmossBundle::class => ['all' => true],
 *
 * and configure config/packages/enigmoss.yaml (see docs/symfony.md).
 */
final class EnigmossBundle extends AbstractBundle
{
    protected string $extensionAlias = 'enigmoss';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('default_client')->defaultValue('default')->end()
                ->scalarNode('logger')->defaultNull()->info('Logger service id, e.g. "logger"; null disables logging.')->end()
                ->integerNode('webhook_dedupe_ttl')->defaultValue(259200)->end()
                ->scalarNode('webhook_dedupe_cache')->defaultValue('cache.app')->end()
                ->arrayNode('clients')
                    ->requiresAtLeastOneElement()
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('base_url')->isRequired()->cannotBeEmpty()->end()
                            ->scalarNode('project_id')->isRequired()->cannotBeEmpty()->end()
                            ->scalarNode('payment_key')->defaultNull()->end()
                            ->scalarNode('payout_key')->defaultNull()->end()
                            ->floatNode('timeout')->defaultValue(30.0)->end()
                            ->floatNode('connect_timeout')->defaultValue(10.0)->end()
                            ->integerNode('max_retries')->defaultValue(2)->min(0)->max(10)->end()
                            ->floatNode('max_retry_delay')->defaultValue(10.0)->end()
                            ->integerNode('webhook_tolerance')->defaultValue(300)->end()
                            ->booleanNode('allow_insecure_http')->defaultFalse()->end()
                            ->enumNode('transport')->values(['curl', 'symfony'])->defaultValue('curl')
                                ->info('"symfony" sends requests through the http_client service (profiler, MockHttpClient).')->end()
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * @param array<array-key, mixed> $config Validated by configure().
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services();
        $verifiers = [];
        $clientRefs = [];
        $clients = \is_array($config['clients'] ?? null) ? $config['clients'] : [];
        $logger = \is_string($config['logger'] ?? null) ? $config['logger'] : null;
        $default = \is_string($config['default_client'] ?? null) ? $config['default_client'] : 'default';
        $dedupeCache = \is_string($config['webhook_dedupe_cache'] ?? null) ? $config['webhook_dedupe_cache'] : 'cache.app';
        $dedupeTtl = \is_int($config['webhook_dedupe_ttl'] ?? null) ? $config['webhook_dedupe_ttl'] : 259200;

        foreach ($clients as $name => $client) {
            if (!\is_array($client)) {
                continue;
            }
            $name = (string) $name;
            $transport = $client['transport'] ?? 'curl';
            unset($client['transport']);
            $clientId = 'enigmoss.client.' . $name;

            $services->set($clientId, EnigmossClient::class)
                ->factory([EnigmossClientFactory::class, 'create'])
                ->args([
                    $client,
                    $transport === 'symfony' ? new Reference('http_client') : null,
                    $logger === null ? null : new Reference($logger, ContainerInterface::NULL_ON_INVALID_REFERENCE),
                ]);
            $builder->registerAliasForArgument($clientId, EnigmossClient::class, $name . 'Client');

            $verifierId = 'enigmoss.webhook_verifier.' . $name;
            $services->set($verifierId, WebhookVerifier::class)
                ->factory([EnigmossClientFactory::class, 'verifier'])
                ->args([new Reference($clientId)]);
            $verifiers[$name] = new Reference($verifierId);
            $clientRefs[$name] = new Reference($clientId);
        }

        $services->alias(EnigmossClient::class, 'enigmoss.client.' . $default)->public();
        $services->alias(WebhookVerifier::class, 'enigmoss.webhook_verifier.' . $default);

        $services->set(WebhookValueResolver::class)
            ->args([new ServiceLocatorArgument($verifiers), $default])
            ->tag('controller.argument_value_resolver', ['priority' => 150]);

        $services->set(CacheWebhookDeduplicator::class)
            ->args([new Reference($dedupeCache), $dedupeTtl]);
        $services->alias(WebhookDeduplicatorInterface::class, CacheWebhookDeduplicator::class)->public();

        $services->set(CheckCommand::class)->args([new ServiceLocatorArgument($clientRefs), $default])->tag('console.command');
        $services->set(WebhookTestCommand::class)->args([new ServiceLocatorArgument($clientRefs), $default])->tag('console.command');
    }
}
