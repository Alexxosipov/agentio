<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Install;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * The youtrack MCP server in the Claude Code configuration of the project, managed with the `claude mcp` CLI:
 * `claude mcp get youtrack` tells whether the server is configured (in any scope visible from the project),
 * `claude mcp add --transport http --scope <scope> youtrack <url>/mcp --header "Authorization: Bearer …"` adds it.
 * A local-scope server lives in ~/.claude.json under the project's path: private to the user, never committed.
 */
final readonly class ClaudeMcp
{
    public const string SERVER = 'youtrack';

    public const array SCOPES = ['local', 'user'];

    public function __construct(private string $claudeBinary, private string $basePath) {}

    /**
     * Whether Claude Code already has a youtrack MCP server for the project.
     */
    public function has(): bool
    {
        return $this->run(['mcp', 'get', self::SERVER], 90)->isSuccessful();
    }

    /**
     * Add the youtrack MCP server of the YouTrack instance with the token in its Authorization header.
     *
     * @throws RuntimeException When `claude mcp add` fails
     */
    public function add(string $url, string $token, string $scope = 'local'): void
    {
        $process = $this->run([
            'mcp', 'add', '--transport', 'http', '--scope', $scope, self::SERVER, rtrim($url, '/').'/mcp',
            '--header', 'Authorization: Bearer '.$token,
        ]);

        if (! $process->isSuccessful()) {
            throw new RuntimeException('claude mcp add failed: '.str_replace($token, '[redacted]', trim($process->getErrorOutput().' '.$process->getOutput())));
        }
    }

    /**
     * Remove the youtrack MCP server of the scope (before adding it again with a new URL or token).
     */
    public function remove(string $scope = 'local'): void
    {
        $this->run(['mcp', 'remove', '--scope', $scope, self::SERVER]);
    }

    /**
     * @param  list<string>  $arguments
     */
    private function run(array $arguments, int $timeout = 60): Process
    {
        $process = new Process([$this->claudeBinary, ...$arguments], $this->basePath, null, null, $timeout);
        $process->run();

        return $process;
    }
}
