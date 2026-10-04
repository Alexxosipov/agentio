<?php

declare(strict_types=1);

use Obrazmisli\Agentio\Security\GuardHook;

/**
 * Run the hook with the input Claude Code sends it on stdin.
 *
 * @param  list<string>  $arguments
 * @return array{0: int, 1: string, 2: string}
 */
function runGuardHook(string $input, array $arguments = ['--protected=trunk,main']): array
{
    $streams = array_map(fn () => fopen('php://memory', 'r+'), range(0, 2));
    fwrite($streams[0], $input);
    rewind($streams[0]);
    $status = GuardHook::main($arguments, ...$streams);

    return [$status, ...array_map(fn ($stream): string => (string) stream_get_contents($stream, offset: 0), array_slice($streams, 1))];
}

function hookInput(string $command, string $cwd): string
{
    return (string) json_encode(['tool_name' => 'Bash', 'tool_input' => ['command' => $command], 'cwd' => $cwd]);
}

it('prints a deny decision for a refused command and nothing for an allowed one', function () {
    $project = temporaryDirectory();
    putenv('CLAUDE_PROJECT_DIR='.$project);

    try {
        [$denied, $output] = runGuardHook(hookInput('git push origin trunk', $project));
        [$allowed, $nothing] = runGuardHook(hookInput('php artisan agentio:yt tree XY-2', $project));
        [, $outside] = runGuardHook(hookInput('rm /srv/shared/tmp.txt', $project), ['--protected=trunk', '--root=/srv/shared/', '--root=/']);
        [, $root] = runGuardHook(hookInput('rm -rf /home', $project), ['--protected=trunk', '--root=/']);
    } finally {
        putenv('CLAUDE_PROJECT_DIR');
    }

    expect($denied)->toBe(0)
        ->and(json_decode($output, true)['hookSpecificOutput'])->toMatchArray(['hookEventName' => 'PreToolUse', 'permissionDecision' => 'deny'])
        ->and(json_decode($output, true)['hookSpecificOutput']['permissionDecisionReason'])->toStartWith("agentio guard: pushing to protected branch 'trunk'")
        ->and($allowed)->toBe(0)
        ->and($nothing)->toBe('')
        ->and($outside)->toBe('')
        ->and($root)->toContain('outside of the project directory');
});

it('refuses the command when it cannot decide', function (string $input, array $arguments) {
    [$status, $output, $error] = runGuardHook($input, $arguments);

    expect($status)->toBe(GuardHook::BLOCK)
        ->and($output)->toBe('')
        ->and($error)->toStartWith('agentio guard failed, the command is refused:');
})->with([
    'invalid input' => ['not json', ['--protected=main']],
    'no protected branches' => ['{"tool_input":{"command":"ls"}}', []],
]);

it('builds its command line from the protected branches and the additional directories', function () {
    expect(GuardHook::command(['dev', 'main'], ['/srv/shared']))->toBe("php '".dirname(__DIR__, 3)."/bin/agentio-guard' '--protected=dev,main' '--root=/srv/shared'");
});
