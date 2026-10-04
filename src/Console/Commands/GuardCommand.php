<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Console\Commands;

use Illuminate\Console\Command;
use Obrazmisli\Agentio\Security\BashGuard;
use Obrazmisli\Agentio\Settings;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\StreamableInputInterface;

/**
 * The PreToolUse hook of the Bash tool in the agents' headless sessions (configured in the session settings the
 * loop passes to Claude Code): reads the hook input from stdin and prints a "deny" permission decision when
 * BashGuard refuses the command. Always exits 0.
 */
#[AsCommand(name: 'agentio:guard', description: 'PreToolUse hook of the agent sessions: refuse dangerous Bash commands', hidden: true)]
final class GuardCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'agentio:guard';

    /**
     * @var bool
     */
    protected $hidden = true;

    public function handle(Settings $settings): int
    {
        $stream = $this->input instanceof StreamableInputInterface ? $this->input->getStream() : null;
        $input = json_decode((string) stream_get_contents($stream ?? STDIN), true);
        $input = is_array($input) ? $input : [];
        $toolInput = is_array($input['tool_input'] ?? null) ? $input['tool_input'] : [];
        $command = is_string($toolInput['command'] ?? null) ? $toolInput['command'] : '';
        $cwd = is_string($input['cwd'] ?? null) && $input['cwd'] !== '' ? $input['cwd'] : (string) getcwd();
        $projectDir = getenv('CLAUDE_PROJECT_DIR');
        $project = rtrim(is_string($projectDir) && $projectDir !== '' ? $projectDir : $cwd, '/');
        $baseBranch = getenv('BASE_BRANCH');

        $guard = new BashGuard(
            $project,
            array_values(array_unique([is_string($baseBranch) && $baseBranch !== '' ? $baseBranch : $settings->baseBranch(), 'main', 'master'])),
            [$project, ...$this->additionalDirectories($project)],
        );
        $reason = $guard->reason($command, $cwd);

        if ($reason !== null) {
            $this->line((string) json_encode(['hookSpecificOutput' => [
                'hookEventName' => 'PreToolUse',
                'permissionDecision' => 'deny',
                'permissionDecisionReason' => 'agentio guard: '.$reason,
            ]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return self::SUCCESS;
    }

    /**
     * The additionalDirectories of the project's own Claude Code settings.
     *
     * @return list<string>
     */
    private function additionalDirectories(string $project): array
    {
        $directories = [];

        foreach (['settings.json', 'settings.local.json'] as $file) {
            $settings = json_decode((string) @file_get_contents($project.'/.claude/'.$file), true);
            $permissions = is_array($settings) && is_array($settings['permissions'] ?? null) ? $settings['permissions'] : [];

            foreach (is_array($permissions['additionalDirectories'] ?? null) ? $permissions['additionalDirectories'] : [] as $directory) {
                if (is_string($directory) && $directory !== '') {
                    $directories[] = rtrim($directory, '/');
                }
            }
        }

        return $directories;
    }
}
