<?php

declare(strict_types=1);

use Obrazmisli\Agentio\Console\Commands\GuardCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Run the hook with the input Claude Code sends it on stdin; returns its exit code and output.
 *
 * @return array{0: int, 1: string}
 */
function runGuard(string $command, string $cwd): array
{
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, (string) json_encode(['tool_name' => 'Bash', 'tool_input' => ['command' => $command], 'cwd' => $cwd]));
    rewind($stream);
    $input = new ArrayInput([]);
    $input->setStream($stream);
    $output = new BufferedOutput;
    $guard = app(GuardCommand::class);
    $guard->setLaravel(app());

    return [$guard->run($input, $output), $output->fetch()];
}

it('prints a deny decision for a refused command and nothing for an allowed one', function () {
    $project = hostProject();
    putenv('CLAUDE_PROJECT_DIR='.$project);

    try {
        [$denied, $output] = runGuard('cat .env', $project);
        [$allowed, $nothing] = runGuard('php artisan agentio:yt tree XY-2', $project);
    } finally {
        putenv('CLAUDE_PROJECT_DIR');
    }

    expect($denied)->toBe(0)
        ->and(json_decode($output, true)['hookSpecificOutput'])->toMatchArray(['hookEventName' => 'PreToolUse', 'permissionDecision' => 'deny'])
        ->and(json_decode($output, true)['hookSpecificOutput']['permissionDecisionReason'])->toStartWith('agentio guard: .env holds the YouTrack token')
        ->and($allowed)->toBe(0)
        ->and($nothing)->toBe('');
});

it('protects the base branch of the project and allows the additional directories of its settings', function () {
    $project = hostProject();
    mkdir($project.'/.claude');
    file_put_contents($project.'/.claude/settings.json', json_encode(['permissions' => ['additionalDirectories' => ['/srv/shared/']]]));
    file_put_contents($project.'/.agentio.json', json_encode(['base_branch' => 'trunk']));
    config(['agentio.base_branch' => null]);

    expect(runGuard('git push origin trunk', $project)[1])->toContain("pushing to protected branch 'trunk'")
        ->and(runGuard('rm /srv/shared/tmp.txt', $project)[1])->toBe('')
        ->and(runGuard('rm /srv/other/tmp.txt', $project)[1])->toContain('outside of the project directory');
});
