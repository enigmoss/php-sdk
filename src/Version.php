<?php

declare(strict_types=1);

namespace Enigmoss;

/**
 * SDK version, sent in the User-Agent header. CI checks that it matches the git tag of a release.
 */
final class Version
{
    public const SDK = '1.0.0';

    private function __construct() {}
}
