<?php

declare(strict_types=1);

use Obrazmisli\Agentio\Telegram\Conversation;
use Obrazmisli\Agentio\Telegram\IncomingMessage;

it('keeps the offset, the watermark and a pairing code', function () {
    $conversation = new Conversation(temporaryDirectory().'/telegram');

    expect($conversation->offset())->toBeNull()
        ->and($conversation->watermark())->toBeNull()
        ->and($conversation->pairingCode())->toBeNull();

    $conversation->setOffset(15);
    $conversation->setWatermark(1_791_000_000_000);
    $code = $conversation->startPairing();

    expect($conversation->offset())->toBe(15)
        ->and($conversation->watermark())->toBe(1_791_000_000_000)
        ->and($conversation->pairingCode())->toBe($code)
        ->and($code)->toMatch('/^[0-9a-f]{8}$/');

    $conversation->finishPairing();

    expect($conversation->pairingCode())->toBeNull()->and($conversation->offset())->toBe(15);
});

it('remembers what the sent messages are about', function () {
    $conversation = new Conversation(temporaryDirectory().'/telegram');
    $conversation->remember([501, 502], 'question', 'XY-12', 'Backlog');

    expect($conversation->about(502))->toBe(['kind' => 'question', 'issue' => 'XY-12', 'stage' => 'Backlog'])
        ->and($conversation->about(999))->toBeNull();
});

it('keeps the last messages of the conversation', function () {
    $conversation = new Conversation(temporaryDirectory().'/telegram');

    foreach (range(1, Conversation::HISTORY + 5) as $number) {
        $conversation->addHistory($number % 2 === 0 ? 'bot' : 'developer', 'Сообщение '.$number, 'XY-1');
    }

    $history = $conversation->history(3);

    expect($history)->toHaveCount(3)
        ->and(array_column($history, 'text'))->toBe(['Сообщение 43', 'Сообщение 44', 'Сообщение 45'])
        ->and(count($conversation->history(100)))->toBe(Conversation::HISTORY);
});

it('keeps the messages waiting for the assistant in order', function () {
    $conversation = new Conversation(temporaryDirectory().'/telegram');

    foreach ([12, 3, 7] as $id) {
        $conversation->putInbox((new IncomingMessage($id, $id + 100, '42', 'Текст '.$id)));
    }

    expect($conversation->inboxKeys())->toBe(['3', '7', '12'])
        ->and($conversation->inbox('7')?->text)->toBe('Текст 7')
        ->and($conversation->inbox('../state'))->toBeNull();

    $conversation->forgetInbox('7');

    expect($conversation->inboxKeys())->toBe(['3', '12']);
});
