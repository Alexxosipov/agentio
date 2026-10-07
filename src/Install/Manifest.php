<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Install;

use JsonException;

/**
 * The committed .agentio.json of the host project: the YouTrack project and the development and the production
 * branch the skills were installed for, the knowledge base article ids and the hashes of the installed files (a file
 * whose content still matches its hash was not edited by hand and is updated on reinstall).
 */
final readonly class Manifest
{
    public const string FILE = '.agentio.json';

    /**
     * @param  array<string, string>  $kb  Knowledge base key => article id
     * @param  array<string, string>  $files  Installed path => sha256 of the installed content
     */
    public function __construct(
        public ?string $project = null,
        public ?string $baseBranch = null,
        public array $kb = [],
        public array $files = [],
        public ?string $productionBranch = null,
    ) {}

    public static function path(string $basePath): string
    {
        return rtrim($basePath, '/').'/'.self::FILE;
    }

    public static function exists(string $basePath): bool
    {
        return is_file(self::path($basePath));
    }

    public static function load(string $basePath): self
    {
        $file = self::path($basePath);

        try {
            $data = is_file($file) ? json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR) : [];
        } catch (JsonException) {
            $data = [];
        }

        $data = is_array($data) ? $data : [];

        return new self(
            project: self::stringOf($data['project'] ?? null),
            baseBranch: self::stringOf($data['base_branch'] ?? null),
            kb: self::stringMap($data['kb'] ?? null),
            files: self::stringMap($data['files'] ?? null),
            productionBranch: self::stringOf($data['production_branch'] ?? null),
        );
    }

    /**
     * @param  array<string, string>|null  $kb
     * @param  array<string, string>|null  $files
     */
    public function with(?string $project = null, ?string $baseBranch = null, ?array $kb = null, ?array $files = null, ?string $productionBranch = null): self
    {
        return new self(
            $project ?? $this->project,
            $baseBranch ?? $this->baseBranch,
            $kb ?? $this->kb,
            $files ?? $this->files,
            $productionBranch ?? $this->productionBranch,
        );
    }

    public function save(string $basePath): void
    {
        $files = $this->files;
        ksort($files);

        file_put_contents(self::path($basePath), json_encode([
            'project' => $this->project,
            'base_branch' => $this->baseBranch,
            'production_branch' => $this->productionBranch,
            'kb' => (object) $this->kb,
            'files' => (object) $files,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL);
    }

    private static function stringOf(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array<string, string>
     */
    private static function stringMap(mixed $value): array
    {
        $map = [];

        foreach (is_array($value) ? $value : [] as $key => $item) {
            if (is_string($key) && is_string($item)) {
                $map[$key] = $item;
            }
        }

        return $map;
    }
}
