<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Install;

use JsonException;

/**
 * The committed .agentio.json of the host project: the YouTrack project and base branch the stubs were
 * installed for, the knowledge base article ids and the hashes of the installed files (a file whose
 * content still matches its hash was not edited by hand and is updated on reinstall).
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
    ) {}

    public static function path(string $basePath): string
    {
        return rtrim($basePath, '/').'/'.self::FILE;
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
            project: is_string($data['project'] ?? null) && $data['project'] !== '' ? $data['project'] : null,
            baseBranch: is_string($data['base_branch'] ?? null) && $data['base_branch'] !== '' ? $data['base_branch'] : null,
            kb: self::stringMap($data['kb'] ?? null),
            files: self::stringMap($data['files'] ?? null),
        );
    }

    /**
     * @param  array<string, string>|null  $kb
     * @param  array<string, string>|null  $files
     */
    public function with(?string $project = null, ?string $baseBranch = null, ?array $kb = null, ?array $files = null): self
    {
        return new self($project ?? $this->project, $baseBranch ?? $this->baseBranch, $kb ?? $this->kb, $files ?? $this->files);
    }

    public function save(string $basePath): void
    {
        $kb = $this->kb;
        $files = $this->files;
        ksort($files);

        file_put_contents(self::path($basePath), json_encode([
            'project' => $this->project,
            'base_branch' => $this->baseBranch,
            'kb' => (object) $kb,
            'files' => (object) $files,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL);
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
