<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Console\Concerns;

use Illuminate\Console\Command;
use Obrazmisli\Agentio\Install\EnvFile;
use Obrazmisli\Agentio\Settings;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

/**
 * Runs the shell scripts of the package (its scripts/ directory, never copied into the project) for the
 * agentio:* commands: the project and the settings are passed in the environment, the output is relayed
 * (directly on a TTY, otherwise line by line) and the exit code is the script's.
 *
 * @mixin Command
 */
trait RunsPackageScripts
{
    /**
     * The absolute path of a script of the package, e.g. "agent-loop.sh".
     */
    public static function packageScript(string $name): string
    {
        return dirname(__DIR__, 3).'/scripts/'.$name;
    }

    /**
     * The command line of a script of the package. It runs through bash, so it works without the executable bit,
     * which Composer may not restore when it unpacks a release.
     *
     * @return list<string>
     */
    public static function scriptCommand(string $name): array
    {
        return ['bash', self::packageScript($name)];
    }

    /**
     * The environment of a script: the project (AGENTIO_ROOT) and the settings of config/agentio.php under the
     * names the scripts read. The variables Laravel loaded from the project's .env (APP_ENV, APP_KEY, DB_*, …) are
     * removed (false): inherited by the scripts they would override the .env of every epic worktree and the
     * <env> values of phpunit.xml. The agentio and YouTrack ones stay.
     *
     * @param  array<string, string|null>  $extra
     * @return array<string, string|false>
     */
    protected function scriptEnvironment(Settings $settings, array $extra = []): array
    {
        $basePath = $settings->basePath();
        $loaded = array_filter(
            (new EnvFile($basePath.'/.env'))->keys(),
            fn (string $key): bool => ! str_starts_with($key, 'AGENTIO_') && ! str_starts_with($key, 'YOUTRACK_'),
        );

        return [...array_fill_keys($loaded, false), ...array_filter([
            'AGENTIO_ROOT' => $basePath,
            'YOUTRACK_URL' => Settings::string('agentio.youtrack.url'),
            'YOUTRACK_TOKEN' => Settings::string('agentio.youtrack.token'),
            'AGENTIO_PROJECT' => $settings->project(),
            'BASE_BRANCH' => $settings->baseBranch(),
            'WORKTREES_DIR' => $settings->worktreesPath(),
            'AGENTIO_WORKTREE_SETUP' => Settings::string('agentio.worktree_setup'),
            ...$extra,
        ], fn (?string $value): bool => $value !== null)];
    }

    /**
     * Run the process, relaying its output; returns its exit code.
     */
    protected function runScript(Process $process): int
    {
        $output = $this->output->getOutput();

        if ($output instanceof ConsoleOutputInterface && Process::isTtySupported() && stream_isatty(STDOUT) && stream_isatty(STDIN)) {
            $process->setTty(true);
            $process->run();

            return $process->getExitCode() ?? self::FAILURE;
        }

        // Without a TTY the output is relayed line by line, stdout and stderr separately.
        $pending = [Process::OUT => '', Process::ERR => ''];
        $write = function (string $type, string $text) use ($output): void {
            $stream = $type === Process::ERR && $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
            $stream->write($text, false, OutputInterface::OUTPUT_RAW);
        };

        $process->run(function (string $type, string $buffer) use (&$pending, $write): void {
            $pending[$type] .= $buffer;

            while (($newline = strpos($pending[$type], "\n")) !== false) {
                $write($type, substr($pending[$type], 0, $newline + 1));
                $pending[$type] = substr($pending[$type], $newline + 1);
            }
        });

        foreach ($pending as $type => $rest) {
            if ($rest !== '') {
                $write($type, $rest);
            }
        }

        return $process->getExitCode() ?? self::FAILURE;
    }
}
