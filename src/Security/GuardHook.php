<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Security;

use InvalidArgumentException;
use Throwable;

/**
 * The PreToolUse hook of the Bash tool in the agents' headless sessions (bin/agentio-guard, configured by
 * SessionSettings). It reads the hook input from stdin and prints a "deny" decision when BashGuard refuses the
 * command. The protected branches and the extra directories come from the loop on the command line, never from
 * files of the worktree; the hook runs without the application of the worktree (code the agents edit), and any
 * error denies the command (exit 2) instead of letting it through.
 */
final class GuardHook
{
    /** Exit code with which Claude Code blocks the tool call and shows stderr to the agent. */
    public const int BLOCK = 2;

    /**
     * @param  list<string>  $arguments  --protected=dev,main and --root=/path (repeatable)
     * @param  resource  $stdin
     * @param  resource  $stdout
     * @param  resource  $stderr
     */
    public static function main(array $arguments, mixed $stdin, mixed $stdout, mixed $stderr): int
    {
        try {
            $input = json_decode((string) stream_get_contents($stdin), true, 16, JSON_THROW_ON_ERROR);
            $input = is_array($input) ? $input : [];
            $toolInput = is_array($input['tool_input'] ?? null) ? $input['tool_input'] : [];
            $command = is_string($toolInput['command'] ?? null) ? $toolInput['command'] : '';
            $cwd = is_string($input['cwd'] ?? null) && $input['cwd'] !== '' ? $input['cwd'] : (string) getcwd();
            $projectDir = getenv('CLAUDE_PROJECT_DIR');
            $project = rtrim(is_string($projectDir) && $projectDir !== '' ? $projectDir : $cwd, '/');
            [$protected, $roots] = self::options($arguments);

            $reason = (new BashGuard($project, $protected, [$project, ...$roots]))->reason($command, $cwd);
        } catch (Throwable $exception) {
            fwrite($stderr, 'agentio guard failed, the command is refused: '.$exception->getMessage().PHP_EOL);

            return self::BLOCK;
        }

        if ($reason !== null) {
            fwrite($stdout, (string) json_encode(['hookSpecificOutput' => [
                'hookEventName' => 'PreToolUse',
                'permissionDecision' => 'deny',
                'permissionDecisionReason' => 'agentio guard: '.$reason,
            ]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL);
        }

        return 0;
    }

    /**
     * The command line of the hook for the given settings.
     *
     * @param  list<string>  $protected
     * @param  list<string>  $roots
     */
    public static function command(array $protected, array $roots): string
    {
        return implode(' ', [
            'php',
            escapeshellarg(dirname(__DIR__, 2).'/bin/agentio-guard'),
            escapeshellarg('--protected='.implode(',', $protected)),
            ...array_map(fn (string $root): string => escapeshellarg('--root='.$root), $roots),
        ]);
    }

    /**
     * @param  list<string>  $arguments
     * @return array{list<string>, list<string>}
     */
    private static function options(array $arguments): array
    {
        $protected = [];
        $roots = [];

        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--protected=')) {
                $protected = array_values(array_filter(explode(',', substr($argument, 12)), fn (string $branch): bool => $branch !== ''));
            } elseif (str_starts_with($argument, '--root=')) {
                $root = rtrim(substr($argument, 7), '/');

                if ($root !== '' && str_starts_with($root, '/')) {
                    $roots[] = $root;
                }
            }
        }

        if ($protected === []) {
            throw new InvalidArgumentException('no protected branches given (--protected=)');
        }

        return [$protected, $roots];
    }
}
