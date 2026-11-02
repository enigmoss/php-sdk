<?php

declare(strict_types=1);

namespace Enigmoss\Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

/**
 * Dependency directions inside the package (run by PHPStan through phpat).
 */
final class LayerTest
{
    public function testCoreDoesNotDependOnBridges(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Enigmoss'))
            ->excluding(Selector::inNamespace('Enigmoss\Bridge'))
            ->shouldNotDependOn()
            ->classes(Selector::inNamespace('Enigmoss\Bridge'));
    }

    public function testHttpDependsOnlyOnExceptions(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Enigmoss\Http'))
            ->canOnlyDependOn()
            ->classes(Selector::inNamespace('Enigmoss\Http'), Selector::inNamespace('Enigmoss\Exception'));
    }

    public function testInternalDoesNotDependOnDomainSlices(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Enigmoss\Internal'))
            ->excluding(Selector::classname('Enigmoss\Internal\Paginator'))
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace('Enigmoss\Payment'),
                Selector::inNamespace('Enigmoss\Payout'),
                Selector::inNamespace('Enigmoss\Wallet'),
                Selector::inNamespace('Enigmoss\Refund'),
                Selector::inNamespace('Enigmoss\Transfer'),
                Selector::inNamespace('Enigmoss\Event'),
                Selector::inNamespace('Enigmoss\Webhook'),
                Selector::inNamespace('Enigmoss\Project'),
            );
    }

    public function testEventsDoNotDependOnWebhookVerification(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Enigmoss\Event'))
            ->shouldNotDependOn()
            ->classes(Selector::inNamespace('Enigmoss\Webhook'));
    }

    public function testTestingHelpersDoNotDependOnBridges(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Enigmoss\Testing'))
            ->shouldNotDependOn()
            ->classes(Selector::inNamespace('Enigmoss\Bridge'));
    }
}
