<?php

declare(strict_types=1);

use Obrazmisli\Agentio\Install\FileChange;
use Obrazmisli\Agentio\Install\Installer;
use Obrazmisli\Agentio\Install\Placeholders;

/**
 * @param  array<string, string|null>  $values
 * @return string|null "<path> <status>"
 */
function environmentChange(Installer $installer, array $values): ?string
{
    $change = $installer->writeEnvironment($values);

    return $change instanceof FileChange ? $change->path.' '.$change->status->value : null;
}

it('writes the connection to .env only and reports it as created, updated or unchanged', function () {
    $directory = hostProject();
    $installer = new Installer($directory, dirname(__DIR__, 3).'/stubs', new Placeholders('XY', 'main'));
    $values = ['YOUTRACK_URL' => 'https://yt.example.com', 'YOUTRACK_TOKEN' => 'token', 'AGENTIO_WORKTREES_PATH' => '/srv/wt'];

    expect(environmentChange($installer, ['YOUTRACK_URL' => null, 'YOUTRACK_TOKEN' => '']))->toBeNull()
        ->and(environmentChange($installer, ['AGENTIO_WORKTREES_PATH' => '/srv/wt']))->toBe('.env created')
        ->and(environmentChange($installer, $values))->toBe('.env updated')
        ->and(environmentChange($installer, $values))->toBe('.env unchanged')
        ->and(environmentChange($installer, [...$values, 'YOUTRACK_TOKEN' => 'rotated']))->toBe('.env updated')
        ->and(glob($directory.'/{,.}[!.]*', GLOB_BRACE))->toBe([$directory.'/.env']);
});
