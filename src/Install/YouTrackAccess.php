<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Install;

use Obrazmisli\Agentio\YouTrack\Client;
use Obrazmisli\Agentio\YouTrack\YouTrackException;

/**
 * Checks the YouTrack connection agentio:install was given and creates the project when it is missing.
 *
 * REST: GET /api/users/me (the token owner), GET /api/admin/projects (by short name) and
 * POST /api/admin/projects with {name, shortName, leader: {id}}
 * (https://www.jetbrains.com/help/youtrack/devportal/resource-api-admin-projects.html).
 */
final readonly class YouTrackAccess
{
    public function __construct(private Client $client) {}

    /**
     * The user the token belongs to: {id, login, fullName}.
     *
     * @return array{id: string, login: string, fullName: string}
     *
     * @throws YouTrackException When the URL is wrong, YouTrack is unreachable or the token is rejected
     */
    public function currentUser(): array
    {
        $user = $this->client->get('users/me', ['fields' => 'id,login,fullName']);

        if (! is_string($user['id'] ?? null) || $user['id'] === '') {
            throw new YouTrackException('YouTrack GET users/me returned no user: is the URL the YouTrack instance itself?');
        }

        return [
            'id' => $user['id'],
            'login' => is_string($user['login'] ?? null) ? $user['login'] : '',
            'fullName' => is_string($user['fullName'] ?? null) ? $user['fullName'] : '',
        ];
    }

    /**
     * The project with the short name, or null when it does not exist or is not visible to the token.
     *
     * @return array<array-key, mixed>|null
     *
     * @throws YouTrackException
     */
    public function project(string $shortName): ?array
    {
        return $this->client->project($shortName);
    }

    /**
     * Create a project led by the given user (needs the "Create project" permission).
     *
     * @return array<array-key, mixed> The created project: {id, shortName, name}
     *
     * @throws YouTrackException
     */
    public function createProject(string $shortName, string $name, string $leaderId): array
    {
        return $this->client->post('admin/projects', [
            'name' => $name,
            'shortName' => $shortName,
            'leader' => ['id' => $leaderId],
        ], ['fields' => 'id,shortName,name']);
    }
}
