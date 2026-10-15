<?php

declare(strict_types=1);

use Obrazmisli\Agentio\Install\EnvFile;
use Obrazmisli\Agentio\Telegram\BotSettings;

it('reads the bot settings from the config', function () {
    config([
        'agentio.telegram.token' => '1:abc',
        'agentio.telegram.chat_id' => ' 42 ',
        'agentio.telegram.queue_connection' => null,
        'agentio.telegram.watch_interval' => 1,
    ]);

    $settings = BotSettings::fromConfig();

    expect($settings->isPaired())->toBeTrue()
        ->and($settings->chatId)->toBe('42')
        ->and($settings->queueConnection)->toBe('redis')
        ->and($settings->queue)->toBe('default')
        ->and($settings->watchInterval)->toBe(10);

    config(['agentio.telegram.chat_id' => null]);

    expect(BotSettings::fromConfig()->isConfigured())->toBeTrue()->and(BotSettings::fromConfig()->isPaired())->toBeFalse();
});

it('fingerprints the .env keys the bot processes use', function () {
    $file = temporaryDirectory().'/.env';
    file_put_contents($file, "APP_NAME=x\nAGENTIO_TELEGRAM_BOT_TOKEN=1:a\n");
    $env = new EnvFile($file);
    $before = BotSettings::fingerprint($env);

    file_put_contents($file, "APP_NAME=y\nAGENTIO_TELEGRAM_BOT_TOKEN=1:a\n");
    expect(BotSettings::fingerprint($env))->toBe($before);

    file_put_contents($file, "APP_NAME=y\nAGENTIO_TELEGRAM_BOT_TOKEN=2:b\n");
    expect(BotSettings::fingerprint($env))->not->toBe($before);

    expect(BotSettings::isBotKey('AGENTIO_TRANSCRIPTION_KEY'))->toBeTrue()
        ->and(BotSettings::isBotKey('AGENTIO_PROJECT'))->toBeFalse();
});

it('keeps godmode off unless the config allows it', function () {
    expect(BotSettings::fromConfig()->godmode)->toBeFalse()
        ->and(BotSettings::fromConfig()->godmodeTimeout)->toBe(3600)
        ->and(BotSettings::fromConfig()->godmodeIdle)->toBe(3600);

    config(['agentio.telegram.godmode' => ['enabled' => true, 'timeout' => 5, 'idle' => 900]]);

    expect(BotSettings::fromConfig()->godmode)->toBeTrue()
        ->and(BotSettings::fromConfig()->godmodeTimeout)->toBe(60)
        ->and(BotSettings::fromConfig()->godmodeIdle)->toBe(900);
});
