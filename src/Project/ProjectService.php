<?php

declare(strict_types=1);

namespace Enigmoss\Project;

use Enigmoss\Auth\KeyScope;
use Enigmoss\Internal\Endpoint;
use Enigmoss\Internal\Requester;
use Enigmoss\RequestOptions;

final class ProjectService
{
    /**
     * @internal
     */
    public function __construct(private readonly Requester $requester) {}

    /**
     * Returns the project the key belongs to, the key type and its permissions.
     *
     * Works with either key; a cheap way to check credentials and clock drift (see serverTime).
     * GET /api/v4/project.
     *
     * @throws \Enigmoss\Exception\EnigmossException
     */
    public function get(?RequestOptions $options = null): Project
    {
        $result = $this->requester->execute(Endpoint::get('/project', KeyScope::Any), options: $options);

        return Project::fromPayload($result->payload());
    }

    /**
     * Returns the brand to show your payers: name, logo and the payment form origin.
     *
     * Works with either key and changes nothing. Cache the result for PlatformBrand::$refreshAfter
     * seconds. GET /api/v4/project/platform-brand.
     *
     * @throws \Enigmoss\Exception\EnigmossException
     */
    public function platformBrand(?RequestOptions $options = null): PlatformBrand
    {
        $result = $this->requester->execute(Endpoint::get('/project/platform-brand', KeyScope::Any), options: $options);

        return PlatformBrand::fromPayload($result->payload());
    }
}
