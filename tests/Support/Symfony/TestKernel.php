<?php

declare(strict_types=1);

namespace Enigmoss\Tests\Support\Symfony;

use Enigmoss\Bridge\Symfony\EnigmossBundle;
use Enigmoss\Bridge\Symfony\Webhook\MapEnigmossWebhook;
use Enigmoss\Event\WebhookEvent;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new EnigmossBundle()];
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/enigmoss-sdk-symfony-test/' . md5(__FILE__) . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/enigmoss-sdk-symfony-test/' . md5(__FILE__) . '/log';
    }

    public function webhook(#[MapEnigmossWebhook] WebhookEvent $event): JsonResponse
    {
        return new JsonResponse(['type' => $event->type, 'class' => $event::class]);
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', ['test' => true, 'secret' => 'test', 'http_method_override' => false, 'handle_all_throwables' => true]);
        $container->extension('enigmoss', [
            'clients' => [
                'default' => [
                    'base_url' => 'https://api.example.com',
                    'project_id' => '3f2b8c1e-5d4a-4e7b-9c6f-1a2b3c4d5e6f',
                    'payment_key' => 'test-payment-secret',
                    'payout_key' => 'test-payout-secret',
                ],
                'shop2' => [
                    'base_url' => 'https://api.example.com',
                    'project_id' => 'd6a7f0c2-6b1e-4b9a-9f3e-2c4d5e6f7a8b',
                    'payment_key' => 'second-secret',
                    'transport' => 'symfony',
                ],
            ],
        ]);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('webhook', '/webhooks/enigmoss')->controller([$this, 'webhook'])->methods(['POST']);
    }
}
