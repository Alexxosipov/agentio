<?php

declare(strict_types=1);

use Obrazmisli\Agentio\Telegram\Decision;
use Obrazmisli\Agentio\Telegram\DecisionAction;

it('reads the decision of the assistant from bare JSON or a json block', function (string $text) {
    $decision = Decision::fromText($text);

    expect($decision->reply)->toBe('Понял.')
        ->and($decision->actions)->toBe([
            ['type' => DecisionAction::Answer, 'issue' => 'XY-12', 'comment' => 'В1: б', 'resume' => true, 'summary' => '', 'description' => '', 'confirm' => false],
            ['type' => DecisionAction::Idea, 'issue' => null, 'comment' => '', 'resume' => false, 'summary' => 'Оплата через СБП', 'description' => 'Нужно принимать СБП.', 'confirm' => false],
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

it('drops unknown actions and invalid issue ids', function () {
    $decision = Decision::fromText('{"reply": "Ок", "actions": [{"type": "delete", "issue": "XY-1"}, {"type": "comment", "issue": "XY-1; drop", "comment": "x"}, "junk"]}');

    expect($decision->actions)->toHaveCount(1)
        ->and($decision->actions[0]['type'])->toBe(DecisionAction::Comment)
        ->and($decision->actions[0]['issue'])->toBeNull();
});

it('takes a text without a decision as the reply', function () {
    expect(Decision::fromText("  Просто ответ без JSON.\n"))->toEqual(new Decision('Просто ответ без JSON.'));
});
