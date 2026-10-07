<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio;

use Obrazmisli\Agentio\Install\Manifest;

/**
 * The settings of the cycle in the host project: config/agentio.php (the environment and .env) first, then the
 * values agentio:install recorded in the committed .agentio.json, then the defaults.
 */
final readonly class Settings
{
    public const string DEVELOPMENT_BRANCH = 'dev';

    public const string PRODUCTION_BRANCH = 'main';

    public function __construct(private string $basePath) {}

    public function basePath(): string
    {
        return $this->basePath;
    }

    public function manifest(): Manifest
    {
        return Manifest::load($this->basePath);
    }

    /**
     * The YouTrack project short name, e.g. "TP".
     */
    public function project(): string
    {
        return self::string('agentio.youtrack.project') ?? $this->manifest()->project ?? 'TP';
    }

    /**
     * The development branch (dev): what the develop server runs; the epic branches start from it and reach it
     * through pull requests.
     */
    public function baseBranch(): string
    {
        return self::string('agentio.base_branch') ?? $this->manifest()->baseBranch ?? self::DEVELOPMENT_BRANCH;
    }

    /**
     * The production branch (main): releases of the development branch reach it through a pull request the
     * developer confirms.
     */
    public function productionBranch(): string
    {
        return self::string('agentio.production_branch') ?? $this->manifest()->productionBranch ?? self::PRODUCTION_BRANCH;
    }

    /**
     * The branches agents never push to, switch to or move: the development branch first, then the production one.
     *
     * @return list<string>
     */
    public function protectedBranches(): array
    {
        return array_values(array_unique([$this->baseBranch(), $this->productionBranch(), 'main', 'master']));
    }

    /**
     * The directory epic worktrees are created in (absolute), or null when it was never configured.
     */
    public function worktreesPath(): ?string
    {
        $path = self::string('agentio.worktrees_path');

        if ($path === null) {
            return null;
        }

        return str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1
            ? rtrim($path, '/')
            : rtrim($this->basePath, '/').'/'.rtrim($path, '/');
    }

    /**
     * The Claude Code CLI the agents run (AGENTIO_CLAUDE_BIN).
     */
    public function claudeBinary(): string
    {
        return self::string('agentio.claude_binary') ?? 'claude';
    }

    /**
     * The model of the agents' sessions, or null for Claude Code's default (AGENTIO_CLAUDE_MODEL).
     */
    public function claudeModel(): ?string
    {
        return self::string('agentio.claude_model');
    }

    /**
     * The command line of `php artisan <arguments>` in the project.
     *
     * @return list<string>
     */
    public function artisan(string ...$arguments): array
    {
        return [PHP_BINARY, rtrim($this->basePath, '/').'/artisan', ...array_values($arguments)];
    }

    public static function string(string $key): ?string
    {
        $value = config($key);

        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
