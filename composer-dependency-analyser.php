<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

// The runtime contract of the package: outside src/Bridge only PHP itself, ext-json and the
// optional ext-curl (CurlTransport) and ext-pdo (PdoWebhookDeduplicator) may be used. Bridges are optional integrations whose host
// application provides the framework, PSR-3 or PSR-18 packages.
return (new Configuration())
    ->addPathToExclude(__DIR__ . '/tests')
    ->ignoreErrorsOnPath(__DIR__ . '/src/Bridge', [ErrorType::SHADOW_DEPENDENCY, ErrorType::DEV_DEPENDENCY_IN_PROD])
    ->ignoreErrorsOnExtension('ext-curl', [ErrorType::SHADOW_DEPENDENCY])
    ->ignoreErrorsOnExtension('ext-pdo', [ErrorType::SHADOW_DEPENDENCY]);
