<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Obrazmisli\Agentio\Install\EnvFile;
use Obrazmisli\Agentio\Telegram\Api\Requests\GetMe;
use Obrazmisli\Agentio\Telegram\Api\Requests\GetUpdates;
use Obrazmisli\Agentio\Telegram\Conversation;
use Obrazmisli\Agentio\Telegram\Jobs\SendTelegramMessage;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

const BOT_TOKEN = '987654321:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw';

beforeEach(function () {
    Process::fake(['*horizon:status*' => Process::result('Horizon is running.'), '*' => Process::result()]);
    Queue::fake();
    MockClient::global([
        GetMe::class => MockResponse::make(['ok' => true, 'result' => ['id' => 9, 'is_bot' => true, 'first_name' => 'Agentio PM', 'username' => 'xy_pm_bot']]),
        GetUpdates::class => MockResponse::make(['ok' => true, 'result' => []]),
    ]);
});

function botProject(string $env = "APP_ENV=local\n"): string
{
    $project = hostProject();
    file_put_contents($project.'/.env', $env);
    mkdir($project.'/vendor/laravel/horizon', 0777, true);
    mkdir($project.'/config');
    file_put_contents($project.'/config/horizon.php', "<?php return ['environments' => ['*' => ['supervisor-1' => ['connection' => 'redis', 'queue' => ['default']]]]];");
    config(['agentio.telegram.token' => null, 'agentio.telegram.chat_id' => null, 'agentio.logs_path' => $project.'/storage/logs/agents']);

    return $project;
}

it('checks the token, writes it to .env and shows the pairing link', function () {
    $project = botProject();

    $this->artisan('agentio:setup-telegram', ['token' => BOT_TOKEN, '--transcription' => 'none', '--no-interaction' => true])
        ->expectsOutputToContain('the bot @xy_pm_bot (Agentio PM)')
        ->expectsOutputToContain('https://t.me/xy_pm_bot?start=')
        ->doesntExpectOutputToContain(BOT_TOKEN)
        ->assertSuccessful();

    $env = new EnvFile($project.'/.env');

    expect($env->get('AGENTIO_TELEGRAM_BOT_TOKEN'))->toBe(BOT_TOKEN)
        ->and($env->get('AGENTIO_TRANSCRIPTION_DRIVER'))->toBe('none')
        ->and($env->get('APP_ENV'))->toBe('local')
        ->and(app(Conversation::class)->pairingCode())->toMatch('/^[0-9a-f]{8}$/');

    Process::assertRan(fn ($process): bool => is_array($process->command) && in_array('horizon:terminate', $process->command, true));
});

it('rejects what is not a bot token and a token Telegram refuses', function () {
    botProject();

    $this->artisan('agentio:setup-telegram', ['token' => 'abc', '--no-interaction' => true])->expectsOutputToContain('not a Telegram bot token')->assertFailed();

    MockClient::destroyGlobal();
    MockClient::global([GetMe::class => MockResponse::make(['ok' => false, 'error_code' => 401, 'description' => 'Unauthorized'], 401)]);

    $this->artisan('agentio:setup-telegram', ['token' => BOT_TOKEN, '--no-interaction' => true])->expectsOutputToContain('Telegram rejected the token: Telegram: Unauthorized')->assertFailed();
});

it('sets up voice messages through an OpenAI-compatible API or whisper.cpp', function () {
    $project = botProject();

    $this->artisan('agentio:setup-telegram', [
        'token' => BOT_TOKEN, '--transcription' => 'openai', '--transcription-url' => 'https://api.groq.com/openai/v1/',
        '--transcription-key' => 'gsk_test', '--transcription-model' => 'whisper-large-v3', '--chat-id' => '42', '--no-interaction' => true,
    ])->assertSuccessful();

    $env = new EnvFile($project.'/.env');

    expect($env->get('AGENTIO_TRANSCRIPTION_DRIVER'))->toBe('openai')
        ->and($env->get('AGENTIO_TRANSCRIPTION_URL'))->toBe('https://api.groq.com/openai/v1')
        ->and($env->get('AGENTIO_TRANSCRIPTION_KEY'))->toBe('gsk_test')
        ->and($env->get('AGENTIO_TRANSCRIPTION_MODEL'))->toBe('whisper-large-v3')
        ->and($env->get('AGENTIO_TELEGRAM_CHAT_ID'))->toBe('42');

    $this->artisan('agentio:setup-telegram', ['token' => BOT_TOKEN, '--transcription' => 'whisper', '--whisper-model' => '/models/ggml-medium.bin', '--whisper-bin' => '/nonexistent/whisper-cli', '--no-interaction' => true])
        ->expectsOutputToContain('whisper.cpp CLI not found')
        ->expectsOutputToContain('does not exist')
        ->assertSuccessful();

    expect((new EnvFile($project.'/.env'))->get('AGENTIO_TRANSCRIPTION_DRIVER'))->toBe('whisper')
        ->and((new EnvFile($project.'/.env'))->get('AGENTIO_WHISPER_MODEL'))->toBe('/models/ggml-medium.bin');

    $this->artisan('agentio:setup-telegram', ['token' => BOT_TOKEN, '--transcription' => 'yandex', '--no-interaction' => true])->expectsOutputToContain('Unknown transcription yandex')->assertFailed();
});

it('clears the chat of another bot', function () {
    $project = botProject("AGENTIO_TELEGRAM_BOT_TOKEN=111111:old-token-0123456789abcdefghijklmnopq\nAGENTIO_TELEGRAM_CHAT_ID=42\n");

    $this->artisan('agentio:setup-telegram', ['token' => BOT_TOKEN, '--transcription' => 'none', '--no-pair' => true, '--no-interaction' => true])->assertSuccessful();

    expect((new EnvFile($project.'/.env'))->get('AGENTIO_TELEGRAM_CHAT_ID'))->toBeNull();
});

it('installs Horizon when the project has none', function () {
    $project = hostProject();
    file_put_contents($project.'/.env', "APP_ENV=local\n");

    $this->artisan('agentio:setup-telegram', ['token' => BOT_TOKEN, '--transcription' => 'none', '--no-pair' => true, '--no-interaction' => true])
        ->expectsOutputToContain('composer require laravel/horizon')
        ->assertSuccessful();

    Process::assertRan(fn ($process): bool => $process->command === ['composer', 'require', 'laravel/horizon', '--no-interaction']);
});

it('pairs the chat interactively when the developer presses Start', function () {
    $project = botProject();
    MockClient::destroyGlobal();
    MockClient::global([
        GetMe::class => MockResponse::make(['ok' => true, 'result' => ['id' => 9, 'first_name' => 'PM', 'username' => 'xy_pm_bot']]),
        GetUpdates::class => function () {
            return MockResponse::make(['ok' => true, 'result' => [telegramUpdate(30, ['text' => '/start '.app(Conversation::class)->pairingCode()], '777')]]);
        },
    ]);

    $this->artisan('agentio:setup-telegram', ['token' => BOT_TOKEN, '--transcription' => 'none'])
        ->expectsOutputToContain('Paired with the chat 777 (@dev)')
        ->assertSuccessful();

    expect((new EnvFile($project.'/.env'))->get('AGENTIO_TELEGRAM_CHAT_ID'))->toBe('777')
        ->and(app(Conversation::class)->offset())->toBe(31);
    Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => $job->chatId === '777');
});
