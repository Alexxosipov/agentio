<?php

declare(strict_types=1);

use Obrazmisli\Agentio\Telegram\Decision;
use Obrazmisli\Agentio\Telegram\DecisionAction;

it('reads the decision of the assistant from bare JSON or a json block', function (string $text) {
    $decision = Decision::fromText($text);

    expect($decision->reply)->toBe('Понял.')
        ->and($decision->actions)->toBe([
            ['type' => DecisionAction::Answer, 'issue' => 'XY-12', 'comment' => 'В1: б', 'resume' => true, 'summary' => '', 'description' => '', 'confirm' => false, 'now' => false, 'park' => false],
            ['type' => DecisionAction::Idea, 'issue' => null, 'comment' => '', 'resume' => false, 'summary' => 'Оплата через СБП', 'description' => 'Нужно принимать СБП.', 'confirm' => false, 'now' => false, 'park' => false],
        ]);
})->with([
    'bare' => ['{"reply": "Понял.", "actions": [{"type": "answer", "issue": "xy-12", "comment": "В1: б", "resume": true}, {"type": "idea", "summary": "Оплата через СБП", "description": "Нужно принимать СБП."}]}'],
    'in a block after text' => ["Готово.\n```json\n{\"reply\": \"Понял.\", \"actions\": [{\"type\": \"answer\", \"issue\": \"XY-12\", \"comment\": \"В1: б\", \"resume\": true}, {\"type\": \"idea\", \"summary\": \"Оплата через СБП\", \"description\": \"Нужно принимать СБП.\"}]}\n```"],
]);

it('reads the merges and the releases the developer asks for', function () {
    $decision = Decision::fromText('{"reply": "Сливаю.", "actions": [{"type": "merge", "issue": "xy-2"}, {"type": "release", "confirm": true}, {"type": "release", "confirm": "yes"}]}');

    expect(array_map(fn (array $action): array => [$action['type'], $action['issue'], $action['confirm']], $decision->actions))->toBe([
        [DecisionAction::Merge, 'XY-2', false],
        [DecisionAction::Release, null, true],
        // Only a JSON true confirms a release.
        [DecisionAction::Release, null, false],
    ]);
});

it('reads the pauses of epics, the parked ideas and the ideas taken into development', function () {
    $decision = Decision::fromText('{"reply": "Ок.", "actions": [{"type": "pause", "issue": "xy-2", "now": true, "comment": "Сначала платежи"}, {"type": "resume", "issue": "XY-3"}, {"type": "idea", "summary": "СБП", "park": true}, {"type": "promote", "issue": "XY-7"}, {"type": "pause", "issue": "XY-4", "now": "yes"}]}');

    expect(array_map(fn (array $action): array => [$action['type'], $action['issue'], $action['now'], $action['park'], $action['comment']], $decision->actions))->toBe([
        [DecisionAction::Pause, 'XY-2', true, false, 'Сначала платежи'],
        [DecisionAction::Resume, 'XY-3', false, false, ''],
        [DecisionAction::Idea, null, false, true, ''],
        [DecisionAction::Promote, 'XY-7', false, false, ''],
        // Only a JSON true stops a session right away.
        [DecisionAction::Pause, 'XY-4', false, false, ''],
    ]);
});

it('drops unknown actions and invalid issue ids', function () {
    $decision = Decision::fromText('{"reply": "Ок", "actions": [{"type": "delete", "issue": "XY-1"}, {"type": "comment", "issue": "XY-1; drop", "comment": "x"}, "junk"]}');

    expect($decision->actions)->toHaveCount(1)
        ->and($decision->actions[0]['type'])->toBe(DecisionAction::Comment)
        ->and($decision->actions[0]['issue'])->toBeNull();
});

it('takes a text without a decision as the reply', function () {
    expect(Decision::fromText("  Просто ответ без JSON.\n"))->toEqual(new Decision('Просто ответ без JSON.'));
});
