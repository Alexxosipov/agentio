<?php

declare(strict_types=1);

use Obrazmisli\Agentio\Runtime\LoopState;

it('prints a session log of the logs directory in a readable form', function () {
    $project = hostProject();
    config(['agentio.logs_path' => $project.'/logs']);
    app()->forgetInstance(LoopState::class);
    mkdir($project.'/logs');
    file_put_contents($project.'/logs/XY-5.log', implode("\n", [
        '===== 2026-10-03 14:00:00 /agentio-work-epic XY-5 in /srv =====',
        json_encode(['type' => 'system', 'subtype' => 'init', 'session_id' => 's-1', 'model' => 'claude-opus', 'cwd' => '/srv']),
        json_encode(['type' => 'assistant', 'message' => ['content' => [['type' => 'text', 'text' => "Starting\nnow"], ['type' => 'tool_use', 'name' => 'Bash', 'input' => ['command' => 'php artisan agentio:yt tree XY-5']]]]]),
        json_encode(['type' => 'assistant', 'parent_tool_use_id' => 't-1', 'message' => ['content' => [['type' => 'tool_use', 'name' => 'mcp__youtrack__get_issue', 'input' => ['issueId' => 'XY-6']]]]]),
        json_encode(['type' => 'user', 'message' => ['content' => [['type' => 'tool_result', 'is_error' => true, 'content' => 'Permission denied']]]]),
        json_encode(['type' => 'result', 'subtype' => 'success', 'num_turns' => 12, 'total_cost_usd' => 1.234, 'duration_ms' => 90000, 'result' => 'WORK-EPIC XY-5: REVIEW']),
        '',
    ]));

    $this->artisan('agentio:log', ['session' => 'XY-5'])
        ->expectsOutput('===== 2026-10-03 14:00:00 /agentio-work-epic XY-5 in /srv =====')
        ->expectsOutput('== session s-1 model=claude-opus cwd=/srv')
        ->expectsOutput('💬 Starting now')
        ->expectsOutput('🔧 Bash: php artisan agentio:yt tree XY-5')
        ->expectsOutput('  ↳ 🔧 mcp__youtrack__get_issue: XY-6')
        ->expectsOutput('⚠️  Permission denied')
        ->expectsOutput('== result: success turns=12 cost=$1.23 duration=1.5min')
        ->expectsOutput('WORK-EPIC XY-5: REVIEW')
        ->assertSuccessful();

    $this->artisan('agentio:log', ['session' => 'XY-5', '--lines' => 1])->expectsOutput('WORK-EPIC XY-5: REVIEW')->assertSuccessful();

    $this->artisan('agentio:log', ['session' => 'XY-6'])
        ->expectsOutputToContain('Log not found')
        ->assertFailed();
});
