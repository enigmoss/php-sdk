<?php

declare(strict_types=1);

namespace Enigmoss\Internal;

use Enigmoss\Auth\KeyScope;
use Enigmoss\ClientConfig;
use Enigmoss\Exception\ConfigurationException;
use Enigmoss\Secret;

/**
 * @internal
 */
final class KeyResolver
{
    public static function resolve(ClientConfig $config, KeyScope $scope, string $method, string $path): Secret
    {
        $key = match ($scope) {
            KeyScope::Payment => $config->paymentKey,
            KeyScope::Payout => $config->payoutKey,
            KeyScope::Any => $config->paymentKey ?? $config->payoutKey,
        };

        if ($key === null) {
            throw new ConfigurationException(\sprintf(
                '%s %s requires the %s key; pass %s to ClientConfig.',
                $method,
                $path,
                $scope->value,
                $scope === KeyScope::Payout ? 'payoutKey' : 'paymentKey',
            ));
        }

        return $key;
    }
}
