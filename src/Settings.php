<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio;

use Obrazmisli\Agentio\Install\Manifest;
use Obrazmisli\Agentio\Runtime\MergePolicy;
use ValueError;

/**
 * The settings of the cycle in the host project: config/agentio.php (the environment and .env) first, then the
 * values agentio:install recorded in the committed .agentio.json, then the defaults.
 */
final readonly class Settings
{
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
     * The branch the epic branches start from and humans merge into.
     */
    public function baseBranch(): string
    {
        return self::string('agentio.base_branch') ?? $this->manifest()->baseBranch ?? 'main';
    }

    /**
     * @throws ValueError When AGENTIO_MERGE_POLICY or .agentio.json holds an unknown policy
     */
    public function mergePolicy(): MergePolicy
    {
        $value = self::string('agentio.merge_policy') ?? $this->manifest()->mergePolicy;

        return $value === null ? MergePolicy::LocalBranch : MergePolicy::from($value);
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

    public static function string(string $key): ?string
    {
        $value = config($key);

        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
