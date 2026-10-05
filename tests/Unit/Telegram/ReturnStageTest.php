<?php

declare(strict_types=1);

use Obrazmisli\Agentio\Telegram\ReturnStage;
use Obrazmisli\Agentio\YouTrack\Issue;

it('finds the Stage an [AGENT:BLOCKED] names', function (string $text, ?string $stage) {
    expect(ReturnStage::named($text))->toBe($stage);
})->with([
    ['**Нужен ответ:** 2 вопроса — комментарием в этой задаче, затем Stage → Backlog.', 'Backlog'],
    ['Затем верните Stage в **Ready**.', 'Ready'],
    ['затем stage -> in progress', 'In Progress'],
    ['Stage → Done', null],
    ['Без стадии', null],
]);

it('falls back to Backlog for an idea and Ready for the rest', function () {
    $idea = Issue::fromMcp(['id' => 'XY-1', 'summary' => '[IDEA] x', 'customFields' => ['Type' => 'Idea', 'Stage' => 'Blocked']]);
    $task = Issue::fromMcp(['id' => 'XY-2', 'summary' => '[TASK] x', 'customFields' => ['Type' => 'Task', 'Stage' => 'Blocked']]);

    expect(ReturnStage::of($idea, null))->toBe('Backlog')
        ->and(ReturnStage::of($task, 'нет стадии'))->toBe('Ready')
        ->and(ReturnStage::of($task, 'затем Stage → Analysis'))->toBe('Analysis');
});
