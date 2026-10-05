<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Console\Commands;

use Illuminate\Console\Command;
use Obrazmisli\Agentio\Install\EnvFile;
use Obrazmisli\Agentio\Install\Preconditions;
use Obrazmisli\Agentio\Queue\Horizon;
use Obrazmisli\Agentio\Settings;
use Obrazmisli\Agentio\Telegram\Bot;
use Obrazmisli\Agentio\Telegram\BotRestart;
use Obrazmisli\Agentio\Telegram\BotSettings;
use Obrazmisli\Agentio\Telegram\Conversation;
use Obrazmisli\Agentio\Telegram\IncomingMessage;
use Obrazmisli\Agentio\Telegram\MessageHandler;
use Obrazmisli\Agentio\Telegram\Messenger;
use Obrazmisli\Agentio\Telegram\TelegramException;
use Obrazmisli\Agentio\Telegram\Transcription\TranscriptionManager;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * Sets up the developer's Telegram bot: checks the token (getMe) and writes it to .env as
 * AGENTIO_TELEGRAM_BOT_TOKEN, sets up voice messages (an OpenAI-compatible API or the local whisper.cpp CLI),
 * makes sure Laravel Horizon works the bot's queue (installing it when it is missing), restarts what uses the old
 * settings and pairs the developer's chat (/start <code>). Safe to rerun; a new token restarts every bot process.
 */
#[AsCommand(name: 'agentio:setup-telegram')]
final class SetupTelegramCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'agentio:setup-telegram
        {token? : The token @BotFather gave the bot (default: AGENTIO_TELEGRAM_BOT_TOKEN; prefer the prompt or .env: arguments stay in the shell history)}
        {--chat-id= : The developer\'s chat id, instead of pairing with /start}
        {--transcription= : Voice messages: openai, whisper or none (asked when interactive)}
        {--transcription-url= : openai: the base URL of the OpenAI-compatible API (default https://api.openai.com/v1)}
        {--transcription-key= : openai: the API key (prefer the prompt or AGENTIO_TRANSCRIPTION_KEY in .env)}
        {--transcription-model= : openai: the model (default whisper-1)}
        {--whisper-bin= : whisper: the whisper.cpp CLI (default whisper-cli)}
        {--whisper-model= : whisper: the path of the ggml model file}
        {--ffmpeg-bin= : whisper: ffmpeg (default ffmpeg)}
        {--skip-horizon : Do not check or install Laravel Horizon}
        {--no-pair : Do not wait for the developer to pair the chat}
        {--pair-timeout=180 : Seconds to wait for /start <code>}';

    /**
     * @var string
     */
    protected $description = 'Set up the developer\'s Telegram bot of agentio: token, voice messages, Horizon, the chat';

    private const string TOKEN_PATTERN = '/^\d{5,}:[A-Za-z0-9_-]{30,}$/';

    public function handle(Settings $settings): int
    {
        $env = new EnvFile($settings->basePath().'/.env');
        $interactive = $this->input->isInteractive();
        $current = Settings::string('agentio.telegram.token') ?? $env->get(BotSettings::TOKEN);

        // 1. The token, checked by Telegram.
        $token = $this->token($current, $interactive);

        if ($token === null) {
            return self::FAILURE;
        }

        try {
            $me = Bot::make($token, Settings::string('agentio.telegram.api_url') ?? 'https://api.telegram.org')->me();
        } catch (TelegramException $exception) {
            $this->components->error('Telegram rejected the token: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Telegram: the bot @{$me['username']} ({$me['name']}).");
        $values = [BotSettings::TOKEN => $token];

        if ($token !== $current && $env->get(BotSettings::CHAT_ID) !== null && $this->stringOption('chat-id') === null) {
            // Another bot: the chat has to be paired with it again.
            $values[BotSettings::CHAT_ID] = '';
        }

        if ($this->stringOption('chat-id') !== null) {
            $values[BotSettings::CHAT_ID] = $this->stringOption('chat-id');
        }

        // 2. Voice messages.
        $transcription = $this->transcription($interactive);

        if ($transcription === null) {
            return self::FAILURE;
        }

        $values = [...$values, ...$transcription];

        // 3. Horizon.
        if (! $this->option('skip-horizon') && ! $this->horizon($settings, $interactive)) {
            return self::FAILURE;
        }

        // 4. .env, and every process that uses the old values.
        $changed = $env->put($values);
        $this->components->twoColumnDetail('.env', $changed ? '<fg=green>updated</> '.implode(', ', array_keys(array_filter($values, fn (string $value): bool => $value !== ''))) : 'unchanged');
        config([
            'agentio.telegram.token' => $token,
            'agentio.telegram.chat_id' => $env->get(BotSettings::CHAT_ID),
        ]);

        if ($changed) {
            foreach ($this->laravel->make(BotRestart::class)->afterEnvChange() as $line) {
                $this->components->twoColumnDetail('Restart', $line);
            }

            $this->line('  A running php artisan agentio:run restarts the bot processes by itself.');
        }

        // 5. The developer's chat.
        if ($env->get(BotSettings::CHAT_ID) === null && ! $this->option('no-pair')) {
            $this->pair($settings, $token, $me['username'], $interactive);
        }

        $this->newLine();
        $this->line('<options=bold>Next steps</>');
        $this->line('  1. Start (or keep running) the loop: php artisan agentio:run — it also starts the bot.');
        $this->line('  2. The state of the bot: php artisan agentio:telegram status; a test message: php artisan agentio:telegram send "Привет"');

        return self::SUCCESS;
    }

    private function token(?string $current, bool $interactive): ?string
    {
        $token = $this->stringArgument('token');

        if ($token === null && $interactive && ($current === null || ! confirm(label: 'A Telegram bot token is already set. Keep it?', default: true))) {
            $token = trim(password(
                label: 'Telegram bot token',
                placeholder: '123456789:AA…',
                required: true,
                validate: fn (string $value): ?string => preg_match(self::TOKEN_PATTERN, trim($value)) === 1 ? null : 'A bot token looks like 123456789:AAE…: ask @BotFather (/newbot or /token).',
                hint: 'Create your own bot with @BotFather (/newbot). The token goes to .env (git-ignored) as AGENTIO_TELEGRAM_BOT_TOKEN.',
            ));
        }

        $token ??= $current;

        if ($token === null || preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            $this->components->error($token === null
                ? 'Pass the token of your bot: php artisan agentio:setup-telegram <token> (create the bot with @BotFather).'
                : 'That is not a Telegram bot token (123456789:AAE…): ask @BotFather.');

            return null;
        }

        return $token;
    }

    /**
     * The .env values of the transcription the developer chose, or null on an invalid choice.
     *
     * @return array<string, string>|null
     */
    private function transcription(bool $interactive): ?array
    {
        $driver = $this->stringOption('transcription');
        $current = Settings::string('agentio.telegram.transcription.driver') ?? TranscriptionManager::NONE;

        if ($driver === null && $interactive) {
            $driver = (string) select(
                label: 'How should voice messages be turned into text?',
                options: [
                    TranscriptionManager::OPENAI => 'openai — an OpenAI-compatible API (OpenAI Whisper, Groq, a local whisper server…)',
                    TranscriptionManager::WHISPER => 'whisper — whisper.cpp on this machine (whisper-cli, a ggml model and ffmpeg)',
                    TranscriptionManager::NONE => 'none — answer text messages only',
                ],
                default: $current,
            );
        }

        $driver ??= $current;

        return match ($driver) {
            TranscriptionManager::OPENAI => $this->openAi($interactive),
            TranscriptionManager::WHISPER => $this->whisper($interactive),
            TranscriptionManager::NONE => ['AGENTIO_TRANSCRIPTION_DRIVER' => TranscriptionManager::NONE],
            default => $this->invalidTranscription($driver),
        };
    }

    /**
     * @return array<string, string>
     */
    private function openAi(bool $interactive): array
    {
        $url = $this->stringOption('transcription-url') ?? Settings::string('agentio.telegram.transcription.url') ?? 'https://api.openai.com/v1';
        $key = $this->stringOption('transcription-key') ?? Settings::string('agentio.telegram.transcription.key');
        $model = $this->stringOption('transcription-model') ?? Settings::string('agentio.telegram.transcription.model') ?? 'whisper-1';

        if ($interactive && $this->stringOption('transcription-url') === null) {
            $url = trim(text(label: 'Base URL of the OpenAI-compatible API', default: $url, required: true, hint: 'OpenAI: https://api.openai.com/v1, Groq: https://api.groq.com/openai/v1, or your own server.'));
            $model = trim(text(label: 'Transcription model', default: $model, required: true, hint: 'OpenAI: whisper-1 or gpt-4o-transcribe; Groq: whisper-large-v3.'));
        }

        if ($interactive && $this->stringOption('transcription-key') === null && ($key === null || ! confirm(label: 'An API key for the transcription is already set. Keep it?', default: true))) {
            $key = trim(password(label: 'API key of the transcription API', hint: 'Leave empty for a server without authentication. It goes to .env as AGENTIO_TRANSCRIPTION_KEY.'));
        }

        return array_filter([
            'AGENTIO_TRANSCRIPTION_DRIVER' => TranscriptionManager::OPENAI,
            'AGENTIO_TRANSCRIPTION_URL' => rtrim($url, '/'),
            'AGENTIO_TRANSCRIPTION_KEY' => $key ?? '',
            'AGENTIO_TRANSCRIPTION_MODEL' => $model,
        ], fn (string $value): bool => $value !== '');
    }

    /**
     * @return array<string, string>|null
     */
    private function whisper(bool $interactive): ?array
    {
        $binary = $this->stringOption('whisper-bin') ?? Settings::string('agentio.telegram.transcription.whisper_binary') ?? 'whisper-cli';
        $model = $this->stringOption('whisper-model') ?? Settings::string('agentio.telegram.transcription.whisper_model');
        $ffmpeg = $this->stringOption('ffmpeg-bin') ?? Settings::string('agentio.telegram.transcription.ffmpeg_binary') ?? 'ffmpeg';

        if ($interactive && $this->stringOption('whisper-model') === null) {
            $binary = trim(text(label: 'whisper.cpp CLI', default: $binary, required: true, hint: 'Build whisper.cpp (https://github.com/ggml-org/whisper.cpp) or install it with your package manager.'));
            $model = trim(text(label: 'Path of the ggml model', default: $model ?? '', required: true, hint: 'E.g. models/ggml-medium.bin (models/download-ggml-model.sh medium); larger models recognise Russian better.'));
            $ffmpeg = trim(text(label: 'ffmpeg', default: $ffmpeg, required: true));
        }

        if ($model === null) {
            $this->components->error('whisper: pass the path of the ggml model with --whisper-model.');

            return null;
        }

        foreach ([$binary => 'whisper.cpp CLI', $ffmpeg => 'ffmpeg'] as $command => $name) {
            if (Preconditions::executable($command) === null) {
                $this->components->warn("{$name} not found ({$command}): voice messages fail until it is installed.");
            }
        }

        if (! is_file($model)) {
            $this->components->warn("The whisper model {$model} does not exist: voice messages fail until it does.");
        }

        return [
            'AGENTIO_TRANSCRIPTION_DRIVER' => TranscriptionManager::WHISPER,
            'AGENTIO_WHISPER_BIN' => $binary,
            'AGENTIO_WHISPER_MODEL' => $model,
            'AGENTIO_FFMPEG_BIN' => $ffmpeg,
        ];
    }

    private function invalidTranscription(string $driver): null
    {
        $this->components->error("Unknown transcription {$driver}: use openai, whisper or none.");

        return null;
    }

    /**
     * Make sure Horizon is installed and works the bot's queue; returns false when it cannot be installed.
     */
    private function horizon(Settings $settings, bool $interactive): bool
    {
        $horizon = $this->laravel->make(Horizon::class);

        if (! $horizon->isInstalled() || ! $horizon->isConfigured()) {
            if ($interactive && ! confirm(label: 'The bot\'s messages go through the queue, worked by Laravel Horizon, which the project does not have. Install it?', default: true, hint: 'composer require laravel/horizon (and predis/predis without the redis extension), php artisan horizon:install.')) {
                $this->components->warn('Horizon is not installed: agentio:run does not start the bot without it.');

                return true;
            }

            try {
                foreach ($horizon->install() as $step) {
                    $this->components->twoColumnDetail('Horizon', $step);
                }
            } catch (RuntimeException $exception) {
                $this->components->error('Cannot install Horizon: '.$exception->getMessage());

                return false;
            }
        }

        $bot = BotSettings::fromConfig();
        $file = $settings->basePath().'/config/horizon.php';
        $config = is_file($file) ? require $file : null;
        $problem = Horizon::queueProblem(is_array($config) ? $config : null, (string) $this->laravel->environment(), $bot->queueConnection, $bot->queue);

        if ($problem !== null) {
            $this->components->warn('Horizon: '.$problem.'.');
        }

        $status = $horizon->status();
        $this->components->twoColumnDetail('Horizon', $status->value.($status->isRunning() ? '' : ' — agentio:run starts it when nothing else runs it'));

        return true;
    }

    /**
     * Wait for /start <code> from the developer and record the chat.
     */
    private function pair(Settings $settings, string $token, string $username, bool $interactive): void
    {
        $conversation = $this->laravel->make(Conversation::class);
        $code = $conversation->startPairing();
        $link = "https://t.me/{$username}?start={$code}";

        $this->newLine();
        $this->line('<options=bold>Pair your chat with the bot</>');
        $this->line("  Open {$link} and press Start (or send the bot: /start {$code}).");

        if (! $interactive) {
            $this->line('  The code is valid for an hour; a running agentio:run pairs the chat when it receives it.');

            return;
        }

        $bot = Bot::make($token, Settings::string('agentio.telegram.api_url') ?? 'https://api.telegram.org');
        $deadline = time() + max(10, (int) $this->option('pair-timeout'));
        $offset = $conversation->offset();

        while (time() < $deadline) {
            try {
                $updates = $bot->updates($offset, min(10, max(1, $deadline - time())));
            } catch (TelegramException $exception) {
                if ($exception->isConflict()) {
                    $this->line('  agentio:run is listening to the bot right now: it pairs the chat when you press Start (the code is valid for an hour).');

                    return;
                }

                $this->components->warn('Telegram: '.$exception->getMessage());

                return;
            }

            foreach ($updates as $update) {
                $offset = is_int($update['update_id'] ?? null) ? $update['update_id'] + 1 : $offset;
                $message = IncomingMessage::fromUpdate($update);

                if ($message !== null && $message->command() === 'start' && hash_equals($code, $message->commandArgument())) {
                    $conversation->setOffset((int) $offset);
                    $conversation->finishPairing();
                    (new EnvFile($settings->basePath().'/.env'))->put([BotSettings::CHAT_ID => $message->chatId]);
                    config(['agentio.telegram.chat_id' => $message->chatId]);
                    $this->laravel->make(BotRestart::class)->afterEnvChange();
                    $this->laravel->make(Messenger::class)->send("✅ Бот привязан к этому чату.\n\n".MessageHandler::HELP, 'help', chatId: $message->chatId);
                    $this->components->info("Paired with the chat {$message->chatId}".($message->from === null ? '' : " (@{$message->from})").' — written to .env as AGENTIO_TELEGRAM_CHAT_ID.');

                    return;
                }
            }
        }

        $this->components->warn('No /start with the code yet: the code is valid for an hour, a running agentio:run pairs the chat when it arrives (or run this command again).');
    }

    private function stringArgument(string $name): ?string
    {
        $value = $this->argument($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
