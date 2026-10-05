<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Telegram;

use Illuminate\Support\Facades\Process;
use Obrazmisli\Agentio\Install\EnvFile;
use Obrazmisli\Agentio\Runtime\LoopState;
use Obrazmisli\Agentio\Settings;

/**
 * Starts the assistant for a message (php artisan agentio:telegram assist <key>) detached from the queue worker:
 * a session of Claude Code takes minutes, longer than a queue job may run. The output goes to
 * <logs>/telegram-assistant.log. It reads .env afresh, like every bot process.
 */
final readonly class AssistantLauncher
{
    public function __construct(
        private Settings $settings,
        private LoopState $loop,
    ) {}

    public function launch(string $key): bool
    {
        if (! is_dir($this->loop->logsPath())) {
            mkdir($this->loop->logsPath(), 0775, true);
        }

        $script = 'if command -v setsid >/dev/null 2>&1; then setsid "$@" >>"$AGENTIO_ASSISTANT_LOG" 2>&1 </dev/null & else nohup "$@" >>"$AGENTIO_ASSISTANT_LOG" 2>&1 </dev/null & fi';

        return Process::path($this->settings->basePath())
            ->env([...(new EnvFile($this->settings->basePath().'/.env'))->unloaded(), 'AGENTIO_ASSISTANT_LOG' => $this->loop->logPath('telegram-assistant')])
            ->timeout(30)
            ->run(['bash', '-c', $script, 'bash', ...$this->settings->artisan('agentio:telegram', 'assist', $key)])
            ->successful();
    }
}
