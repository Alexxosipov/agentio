<?php

declare(strict_types=1);

use Obrazmisli\Agentio\Telegram\IncomingMessage;

it('reads a text message that replies to a message of the bot', function () {
    $message = IncomingMessage::fromUpdate(telegramUpdate(7, [
        'text' => 'В1: б',
        'reply_to_message' => ['message_id' => 500, 'from' => ['id' => 1, 'is_bot' => true], 'text' => '❓ Вопросы по XY-12'],
    ]));

    expect($message)->not->toBeNull()
        ->and($message?->updateId)->toBe(7)
        ->and($message?->messageId)->toBe(1007)
        ->and($message?->chatId)->toBe('42')
        ->and($message?->from)->toBe('dev')
        ->and($message?->text)->toBe('В1: б')
        ->and($message?->replyToId)->toBe(500)
        ->and($message?->replyToText)->toBe('❓ Вопросы по XY-12')
        ->and($message?->replyToBot)->toBeTrue()
        ->and($message?->isVoice())->toBeFalse()
        ->and(IncomingMessage::fromArray((array) $message?->toArray()))->toEqual($message);
});

it('reads a voice message and an audio file', function (string $field) {
    $update = telegramUpdate(8);
    unset($update['message']['text']);
    $update['message'][$field] = ['file_id' => 'voice-1', 'duration' => 12];

    $message = IncomingMessage::fromUpdate($update);

    expect($message?->isVoice())->toBeTrue()
        ->and($message?->voiceFileId)->toBe('voice-1')
        ->and($message?->voiceDuration)->toBe(12)
        ->and($message?->text)->toBe('');
})->with(['voice', 'audio']);

it('ignores updates without text or voice', function (array $update) {
    expect(IncomingMessage::fromUpdate($update))->toBeNull();
})->with([
    'edited' => [['update_id' => 1, 'edited_message' => ['message_id' => 1]]],
    'sticker' => [['update_id' => 1, 'message' => ['message_id' => 1, 'chat' => ['id' => 1], 'sticker' => []]]],
]);

it('parses bot commands', function () {
    $start = IncomingMessage::fromUpdate(telegramUpdate(9, ['text' => '/start@AgentioBot a1b2c3d4']));
    $plain = IncomingMessage::fromUpdate(telegramUpdate(10, ['text' => 'что в работе?']));

    expect($start?->command())->toBe('start')
        ->and($start?->commandArgument())->toBe('a1b2c3d4')
        ->and($plain?->command())->toBeNull();
});
