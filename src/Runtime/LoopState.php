<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Runtime;

use Carbon\CarbonImmutable;

/**
 * Local state of the autonomous loop, read from the files scripts/agent-loop.sh keeps in the logs directory:
 *
 * - loop.log            the loop's own log ("[Y-m-d H:i:s] message");
 * - loop.pid            pid of the running loop process (written by agentio:run);
 * - <ID>.pid / <ID>.log epic sessions (/work-epic), <ID>.restarts their restart counter;
 * - plan-<ID>.pid / plan-<ID>.log planning sessions (/plan);
 * - .agent-stop         in the project root: the loop exits after the current step.
 */
final readonly class LoopState
{
    public const string LOOP_LOG = 'loop.log';

    public const string LOOP_PID = 'loop.pid';

    /**
     * @param  string|null  $timezone  Timezone of the loop's local timestamps (defaults to PHP's timezone)
     */
    public function __construct(
        private string $logsPath,
        private string $stopFile,
        private ?string $timezone = null,
    ) {}

    public function logsPath(): string
    {
        return $this->logsPath;
    }

    public function stopFile(): string
    {
        return $this->stopFile;
    }

    public function isStopRequested(): bool
    {
        return is_file($this->stopFile);
    }

    /**
     * Create the stop flag: the loop exits after its current step, sessions finish on their own.
     */
    public function requestStop(): void
    {
        touch($this->stopFile);
    }

    public function clearStop(): void
    {
        if (is_file($this->stopFile)) {
            unlink($this->stopFile);
        }
    }

    /**
     * Running (loop.pid alive, or — without loop.pid — loop.log's last start marker has no stop marker after it),
     * Stopping (running with the stop flag set) or Stopped.
     */
    public function status(): LoopStatus
    {
        $pid = $this->loopPid();
        $running = $pid !== null ? self::isProcessAlive($pid) : $this->loopLogSaysRunning();

        return match (true) {
            ! $running => LoopStatus::Stopped,
            $this->isStopRequested() => LoopStatus::Stopping,
            default => LoopStatus::Running,
        };
    }

    /**
     * The pid recorded in loop.pid, if any.
     */
    public function loopPid(): ?int
    {
        return $this->readPid($this->path(self::LOOP_PID));
    }

    /**
     * Sessions that have a pid file, alive or not (a dead one is reaped by the loop's next pass).
     *
     * @return list<Session>
     */
    public function sessions(): array
    {
        $sessions = [];

        foreach (glob($this->path('*.pid')) ?: [] as $file) {
            $name = basename($file, '.pid');

            if ($name !== basename(self::LOOP_PID, '.pid')) {
                $sessions[] = $this->sessionFromPidFile($name, $file);
            }
        }

        usort($sessions, fn (Session $a, Session $b): int => strnatcasecmp($a->name, $b->name));

        return $sessions;
    }

    /**
     * @return list<Session>
     */
    public function runningSessions(): array
    {
        return array_values(array_filter($this->sessions(), fn (Session $session): bool => $session->alive));
    }

    /**
     * The session with the given name ("TP-2" or "plan-TP-1"), or null when it has no pid file.
     */
    public function session(string $name): ?Session
    {
        $file = $this->path($name.'.pid');

        return is_file($file) ? $this->sessionFromPidFile($name, $file) : null;
    }

    /**
     * The last lines of loop.log, oldest first.
     *
     * @return list<LoopLogEntry>
     */
    public function loopLog(int $lines = 100): array
    {
        $entries = [];

        foreach (ReverseLineReader::lines($this->path(self::LOOP_LOG), 4 * 1024 * 1024) as $line) {
            if (count($entries) >= $lines) {
                break;
            }

            if (trim($line) !== '') {
                $entries[] = LoopLogEntry::parse($line, $this->timezone);
            }
        }

        return array_reverse($entries);
    }

    /**
     * Path of a session log: "<logs>/<name>.log".
     */
    public function logPath(string $name): string
    {
        return $this->path($name.'.log');
    }

    public function epicLogPath(string $epicId): string
    {
        return $this->logPath($epicId);
    }

    public function planLogPath(string $ideaId): string
    {
        return $this->logPath('plan-'.$ideaId);
    }

    public function sessionLog(string $name): SessionLog
    {
        return new SessionLog($this->logPath($name), $this->timezone);
    }

    /**
     * How many times the epic session ended before Review in a row (<ID>.restarts).
     */
    public function restarts(string $epicId): int
    {
        $file = $this->path($epicId.'.restarts');

        return is_file($file) ? (int) trim((string) file_get_contents($file)) : 0;
    }

    /**
     * Whether a process with the given pid exists (signal 0 or /proc).
     */
    public static function isProcessAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0) || (function_exists('posix_get_last_error') && posix_get_last_error() === 1);
        }

        return is_dir('/proc/'.$pid);
    }

    private function sessionFromPidFile(string $name, string $file): Session
    {
        $pid = $this->readPid($file);
        $kind = SessionKind::fromName($name);
        $mtime = filemtime($file);

        return new Session(
            name: $name,
            issueId: $kind === SessionKind::Plan ? substr($name, strlen('plan-')) : $name,
            kind: $kind,
            pid: $pid,
            alive: $pid !== null && self::isProcessAlive($pid),
            logPath: $this->logPath($name),
            startedAt: $mtime === false ? null : CarbonImmutable::createFromTimestampUTC($mtime),
            restarts: $kind === SessionKind::Epic ? $this->restarts($name) : 0,
        );
    }

    private function readPid(string $file): ?int
    {
        if (! is_file($file)) {
            return null;
        }

        $pid = trim((string) file_get_contents($file));

        return ctype_digit($pid) && (int) $pid > 0 ? (int) $pid : null;
    }

    private function loopLogSaysRunning(): bool
    {
        foreach (ReverseLineReader::lines($this->path(self::LOOP_LOG), 4 * 1024 * 1024) as $line) {
            if (str_contains($line, '] agent loop started')) {
                return true;
            }

            if (str_contains($line, '] agent loop stopped')) {
                return false;
            }
        }

        return false;
    }

    private function path(string $file): string
    {
        return rtrim($this->logsPath, '/\\').DIRECTORY_SEPARATOR.$file;
    }
}
