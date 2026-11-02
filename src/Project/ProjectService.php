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
}
