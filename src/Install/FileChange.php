<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Install;

/**
 * What the installer did (or, in a dry run, would do) with one file of the host project.
 */
final readonly class FileChange
{
    public function __construct(
        public string $path,
        public FileStatus $status,
        public string $note = '',
    ) {}
}
