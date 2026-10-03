<?php

declare(strict_types=1);

use Obrazmisli\Agentio\Install\FileChange;
use Obrazmisli\Agentio\Install\Installer;
use Obrazmisli\Agentio\Install\Placeholders;
use Obrazmisli\Agentio\Runtime\MergePolicy;

/**
 * @param  array<string, string|null>  $values
 * @return array<string, string> Path => status
 */
function environmentChanges(Installer $installer, array $values): array
{
    return collect($installer->writeEnvironment($values))->mapWithKeys(fn (FileChange $change): array => [$change->path => $change->status->value])->all();
}

it('reports the files of the connection as created, updated or unchanged', function () {
    $directory = hostProject();
    $installer = new Installer($directory, dirname(__DIR__, 3).'/stubs', new Placeholders('XY', 'main', MergePolicy::LocalBranch));
    $values = ['YOUTRACK_URL' => 'https://yt.example.com', 'YOUTRACK_TOKEN' => 'token', 'AGENTIO_PROJECT' => 'XY'];

    expect(environmentChanges($installer, ['YOUTRACK_URL' => null, 'YOUTRACK_TOKEN' => '']))->toBe([])
        ->and(environmentChanges($installer, ['AGENTIO_PROJECT' => 'XY']))->toBe(['.env' => 'created'])
        ->and(environmentChanges($installer, $values))->toBe(['.env' => 'updated', '.claude/settings.local.json' => 'created'])
        ->and(environmentChanges($installer, $values))->toBe(['.env' => 'unchanged', '.claude/settings.local.json' => 'unchanged'])
        ->and(environmentChanges($installer, [...$values, 'YOUTRACK_TOKEN' => 'rotated']))->toBe(['.env' => 'updated', '.claude/settings.local.json' => 'updated']);
});
