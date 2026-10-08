<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Obrazmisli\Agentio\Tests\Fakes\FakeYouTrackMcp;

beforeEach(function () {
    Http::preventStrayRequests();
    config([
        'agentio.youtrack.url' => FakeYouTrackMcp::URL,
        'agentio.youtrack.token' => 'secret-token',
        'agentio.youtrack.project' => 'XY',
    ]);
});

/**
 * An epic XY-2 with two stories: XY-3 (tasks XY-5 done, XY-6 ready after XY-5) and XY-4 (task XY-7 waiting for
 * XY-6, task XY-8 claimed), plus the idea XY-1 related to the epic.
 */
function epicProject(): FakeYouTrackMcp
{
    return (new FakeYouTrackMcp)
        ->issue('XY-1', 'Idea', 'Done', tags: ['idea'], relatesTo: ['XY-2'])
        ->issue('XY-2', 'Epic', 'Ready')
        ->issue('XY-3', 'Story', 'Ready', 'XY-2')
        ->issue('XY-4', 'Story', 'Ready', 'XY-2')
        ->issue('XY-5', 'Task', 'Done', 'XY-3')
        ->issue('XY-6', 'Task', 'Ready', 'XY-3', ['XY-5'])
        ->issue('XY-7', 'Task', 'Ready', 'XY-4', ['XY-6'])
        ->issue('XY-8', 'Task', 'Ready', 'XY-4', tags: ['agent-claimed'])
        ->fake();
}

/**
 * @param  array<string, mixed>  $parameters
 * @return array{0: int, 1: string}
 */
function yt(array $parameters): array
{
    $status = Artisan::call('agentio:yt', $parameters);

    return [$status, Artisan::output()];
}

it('lists the ideas waiting for planning', function () {
    (new FakeYouTrackMcp)
        ->issue('XY-10', 'Task', 'Backlog', tags: ['idea'], summary: 'Tagged idea')
        ->issue('XY-2', 'Idea', 'Backlog', summary: 'Typed idea')
        ->issue('XY-3', 'Idea', 'Analysis')
        ->issue('XY-4', 'Idea', 'Backlog', tags: ['agent-claimed'])
        ->fake();

    [$status, $output] = yt(['action' => 'ideas']);

    expect($status)->toBe(0)
        ->and($output)->toBe("XY-2 Typed idea\nXY-10 Tagged idea\n");

    [, $json] = yt(['action' => 'ideas', '--json' => true]);

    expect(json_decode($json, true))->toBe([['id' => 'XY-2', 'summary' => 'Typed idea'], ['id' => 'XY-10', 'summary' => 'Tagged idea']]);
});

it('lists ready epics with their first wave of tasks', function () {
    epicProject()
        ->issue('XY-20', 'Epic', 'Ready', summary: '[EPIC] Без готовых задач')
        ->issue('XY-21', 'Epic', 'Ready', tags: ['agent-claimed']);

    [$status, $json] = yt(['action' => 'ready-epics', '--json' => true]);

    expect($status)->toBe(0)
        ->and(json_decode($json, true))->toBe([['id' => 'XY-2', 'summary' => '[EPIC] Summary of XY-2', 'branch' => 'XY-2', 'readyTasks' => ['XY-6']]]);
});

it('prints the epic tree depth first with readiness and unmet dependencies', function () {
    epicProject();

    [$status, $output] = yt(['action' => 'tree', 'id' => 'XY-2']);

    expect($status)->toBe(0)
        ->and(explode("\n", trim($output)))->toBe([
            'XY-2    Ready        Epic   [EPIC] Summary of XY-2 [READY]',
            '  XY-3    Ready        Story  [STORY] Summary of XY-3',
            '    XY-5    Done         Task   [TASK] Summary of XY-5',
            '    XY-6    Ready        Task   [TASK] Summary of XY-6 [READY]',
            '  XY-4    Ready        Story  [STORY] Summary of XY-4',
            '    XY-7    Ready        Task   [TASK] Summary of XY-7 waits:XY-6',
            '    XY-8    Ready        Task   [TASK] Summary of XY-8 [CLAIMED]',
        ]);

    [, $json] = yt(['action' => 'tree', 'id' => 'XY-2', '--json' => true]);
    $nodes = json_decode($json, true);

    expect($nodes[5])->toMatchArray(['id' => 'XY-7', 'parent' => 'XY-4', 'dependsOn' => ['XY-6'], 'unmetDependencies' => ['XY-6'], 'ready' => false, 'depth' => 2]);
});

it('counts a dependency in Review inside the same epic as met', function () {
    epicProject()->issues['XY-6']['fields']['Stage'] = 'Review';

    [, $output] = yt(['action' => 'ready-tasks', 'id' => 'XY-2']);

    expect($output)->toBe("XY-7 (story XY-4) [TASK] Summary of XY-7\n");
});

it('validates an epic and the epics of an idea', function () {
    $server = epicProject();

    expect(yt(['action' => 'validate', 'id' => 'XY-1']))->toBe([0, "OK epics=XY-2\n"]);

    $server->issue('XY-9', 'Story', 'Ready', 'XY-2', summary: 'No prefix')
        ->issue('XY-30', 'Task', 'Ready', null, ['XY-31'])
        ->issue('XY-31', 'Task', 'Ready', null, ['XY-30']);
    $server->issues['XY-6']['dependsOn'] = ['XY-5', 'XY-30'];

    [$status, $output] = yt(['action' => 'validate', 'id' => 'XY-2']);

    expect($status)->toBe(2)
        ->and($output)->toContain('PROBLEMS epics=XY-2', "XY-9: Type 'Story' does not match the summary prefix.", 'XY-9: the story has no tasks.', 'Dependency cycle: XY-30 -> XY-31 -> XY-30');
});

it('reports an idea without epics as a problem', function () {
    (new FakeYouTrackMcp)->issue('XY-1', 'Idea', 'Analysis')->fake();

    expect(yt(['action' => 'validate', 'id' => 'XY-1'])[0])->toBe(2);
});

it('claims an issue: [AGENT:START], the tag, In Progress, then checks the claim', function () {
    $server = epicProject();

    [$status, $output] = yt(['action' => 'claim', 'id' => 'XY-6', '--as' => 'XY-6', '--plan' => '1. Do it', '--worktree' => '/w/XY-2', '--branch' => 'epic/XY-2-x']);
    $owner = gethostname().':/w/XY-2#XY-6';

    expect($status)->toBe(0)
        ->and($output)->toBe("CLAIMED XY-6 as {$owner}\n")
        ->and($server->comments['XY-6'][0]['text'])->toBe("[AGENT:START]\nowner: `{$owner}`\nbranch: `epic/XY-2-x`\nworktree: `/w/XY-2`\n\n1. Do it")
        ->and($server->issues['XY-6']['tags'])->toBe(['agent-claimed'])
        ->and($server->issues['XY-6']['fields']['Stage'])->toBe('In Progress');

    expect(yt(['action' => 'claim', 'id' => 'XY-6', '--as' => 'XY-6', '--worktree' => '/w/XY-2', '--branch' => 'epic/XY-2-x']))->toBe([0, "RESUMED XY-6 as {$owner}\n"])
        ->and($server->comments['XY-6'])->toHaveCount(1);
});

it('does not touch an issue claimed by someone else', function () {
    $server = epicProject()->comment('XY-6', "[AGENT:START]\nowner: `other:/w/XY-2#XY-6`");

    [$status, $output] = yt(['action' => 'claim', 'id' => 'XY-6', '--worktree' => '/w/XY-2', '--branch' => 'epic/XY-2-x']);

    expect($status)->toBe(3)
        ->and($output)->toBe("LOST: XY-6 is claimed by other:/w/XY-2#XY-6\n")
        ->and($server->issues['XY-6']['fields']['Stage'])->toBe('Ready')
        ->and($server->callsOf('add_issue_comment'))->toBe([])
        ->and($server->callsOf('manage_issue_tags'))->toBe([]);
});

it('loses a race when another claim lands first', function () {
    $server = epicProject();
    $server->on('add_issue_comment', function (array $arguments) use ($server): string {
        $server->comment('XY-6', "[AGENT:START]\nowner: `other:/w#1`");
        $server->comment('XY-6', (string) $arguments['text']);

        return 'Comment added';
    });

    expect(yt(['action' => 'claim', 'id' => 'XY-6', '--owner' => 'me:/w', '--worktree' => '/w', '--branch' => 'b'])[0])->toBe(3);
});

it('releases an issue: comment, Stage, tag removed', function () {
    $server = epicProject()->comment('XY-8', "[AGENT:START]\nowner: `me:/w`");

    [$status, $output] = yt(['action' => 'release', 'id' => 'XY-8', '--state' => 'done', '--comment' => "[AGENT:DONE]\nDone."]);

    expect($status)->toBe(0)
        ->and($output)->toBe("RELEASED XY-8 -> Done\n")
        ->and($server->issues['XY-8']['fields']['Stage'])->toBe('Done')
        ->and($server->issues['XY-8']['tags'])->toBe([])
        ->and(end($server->comments['XY-8'])['text'])->toBe("[AGENT:DONE]\nDone.");

    expect(yt(['action' => 'release', 'id' => 'XY-6', '--state' => 'Ready'])[0])->toBe(0)
        ->and($server->callsOf('manage_issue_tags'))->toHaveCount(1);
});

it('ends an active claim when the agent releases without a comment that ends it', function () {
    $server = epicProject()->comment('XY-8', "[AGENT:START]\nowner: `me:/w#XY-8`");

    expect(yt(['action' => 'release', 'id' => 'XY-8', '--state' => 'Ready'])[0])->toBe(0)
        ->and(end($server->comments['XY-8'])['text'])->toBe("[AGENT:RELEASE]\nowner: `me:/w#XY-8` → Ready");

    // Released again (the first try failed half-way): nothing is ended twice.
    expect(yt(['action' => 'release', 'id' => 'XY-8', '--state' => 'Ready'])[0])->toBe(0)
        ->and($server->comments['XY-8'])->toHaveCount(2);
});

it('withdraws a claim that failed half-way', function () {
    $server = epicProject();
    $server->on('manage_issue_tags', fn (): array => FakeYouTrackMcp::error('Service unavailable'));

    [$status, $output] = yt(['action' => 'claim', 'id' => 'XY-6', '--owner' => 'me:/w', '--worktree' => '/w', '--branch' => 'b']);

    expect($status)->toBe(1)
        ->and($output)->toContain('Service unavailable')
        ->and(array_column($server->comments['XY-6'], 'text'))->toBe(["[AGENT:START]\nowner: `me:/w`\nbranch: `b`\nworktree: `/w`\n\nThe plan follows in the next [AGENT:START] comment.", "[AGENT:RELEASE]\nThe claim failed: YouTrack MCP manage_issue_tags failed: Service unavailable"])
        ->and($server->issues['XY-6']['fields']['Stage'])->toBe('Ready');
});

it('neither claims nor moves an issue on hold', function () {
    $server = epicProject();
    $server->issues['XY-2']['fields']['Stage'] = 'On Hold';
    $server->issues['XY-2']['tags'] = ['agent-claimed'];
    $server->comment('XY-2', "[AGENT:START]\nowner: `me:/w/XY-2`");

    expect(yt(['action' => 'claim', 'id' => 'XY-2', '--owner' => 'me:/w/XY-2', '--worktree' => '/w/XY-2', '--branch' => 'XY-2']))
        ->toBe([3, "ON_HOLD: XY-2 is on hold (Stage On Hold): leave it as it is; the developer resumes it (php artisan agentio:resume XY-2)\n"])
        ->and(yt(['action' => 'release', 'id' => 'XY-2', '--state' => 'Review'])[0])->toBe(3)
        ->and($server->issues['XY-2']['fields']['Stage'])->toBe('On Hold')
        ->and($server->issues['XY-2']['tags'])->toBe(['agent-claimed'])
        ->and($server->comments['XY-2'])->toHaveCount(1);
});

it('parks an idea: release to On Hold, written as OnHold on the command line', function () {
    $server = (new FakeYouTrackMcp)
        ->issue('XY-7', 'Idea', 'Analysis', tags: ['idea', 'parked', 'agent-claimed'])
        ->comment('XY-7', "[AGENT:START]\nowner: `me:/app#pm`")
        ->fake();

    expect(yt(['action' => 'release', 'id' => 'XY-7', '--state' => 'OnHold']))->toBe([0, "RELEASED XY-7 -> On Hold\n"])
        ->and($server->issues['XY-7']['fields']['Stage'])->toBe('On Hold')
        ->and($server->issues['XY-7']['tags'])->toBe(['idea', 'parked'])
        ->and(yt(['action' => 'ideas'])[1])->toBe('');
});

it('lists the epics and ideas this machine left unfinished', function () {
    $project = hostProject();
    $worktrees = $project.'/worktrees';
    mkdir($worktrees.'/XY-40', 0777, true);
    config(['agentio.worktrees_path' => $worktrees]);
    $host = gethostname();

    (new FakeYouTrackMcp)
        ->issue('XY-40', 'Epic', 'In Progress', tags: ['agent-claimed'])
        ->comment('XY-40', "[AGENT:START]\nowner: `{$host}:{$worktrees}/XY-40`")
        ->issue('XY-41', 'Epic', 'In Progress', tags: ['agent-claimed'])
        ->comment('XY-41', "[AGENT:START]\nowner: `other-host:{$worktrees}/XY-41`")
        ->issue('XY-42', 'Epic', 'In Progress', tags: ['agent-claimed'])
        ->comment('XY-42', "[AGENT:START]\nowner: `{$host}:{$worktrees}/XY-42`")
        ->issue('XY-1', 'Idea', 'Analysis', tags: ['idea', 'agent-claimed'])
        ->comment('XY-1', "[AGENT:START]\nowner: `{$host}:{$project}#pm`")
        ->issue('XY-3', 'Idea', 'Analysis', tags: ['agent-claimed'])
        ->comment('XY-3', "[AGENT:START]\nowner: `{$host}:/elsewhere#pm`")
        ->issue('XY-43', 'Epic', 'On Hold', tags: ['agent-claimed'])
        ->comment('XY-43', "[AGENT:START]\nowner: `{$host}:{$worktrees}/XY-43`")
        ->fake();
    mkdir($worktrees.'/XY-43', 0777, true);

    [$status, $json] = yt(['action' => 'resumable', '--json' => true]);

    // XY-41 is another machine's, the worktree of XY-42 is gone, XY-3 is planned from another checkout, XY-43 is
    // paused by the developer.
    expect($status)->toBe(0)
        ->and(json_decode($json, true))->toBe([
            ['id' => 'XY-40', 'kind' => 'epic', 'summary' => '[EPIC] Summary of XY-40'],
            ['id' => 'XY-1', 'kind' => 'idea', 'summary' => '[IDEA] Summary of XY-1'],
        ]);
});

it('offers and lets claim only the issues reported by the user of the token', function () {
    $project = hostProject();
    $worktrees = $project.'/worktrees';
    mkdir($worktrees.'/XY-31', 0777, true);
    config(['agentio.worktrees_path' => $worktrees]);
    $host = gethostname();

    $server = epicProject()
        ->issue('XY-10', 'Idea', 'Backlog', summary: 'Our idea')
        ->issue('XY-11', 'Idea', 'Backlog', summary: 'Their idea', reporter: 'colleague')
        ->issue('XY-12', 'Task', 'Ready', 'XY-3', reporter: 'colleague')
        ->issue('XY-30', 'Epic', 'Ready', reporter: 'colleague')
        ->issue('XY-32', 'Story', 'Ready', 'XY-30')
        ->issue('XY-33', 'Task', 'Ready', 'XY-32')
        ->issue('XY-31', 'Epic', 'In Progress', tags: ['agent-claimed'], reporter: 'colleague')
        ->comment('XY-31', "[AGENT:START]\nowner: `{$host}:{$worktrees}/XY-31`");

    expect(yt(['action' => 'ideas']))->toBe([0, "XY-10 Our idea\n"])
        ->and(json_decode(yt(['action' => 'ready-epics', '--json' => true])[1], true))->toBe([['id' => 'XY-2', 'summary' => '[EPIC] Summary of XY-2', 'branch' => 'XY-2', 'readyTasks' => ['XY-6']]])
        ->and(array_column(json_decode(yt(['action' => 'ready-tasks', 'id' => 'XY-2', '--json' => true])[1], true), 'id'))->toBe(['XY-6'])
        ->and(json_decode(yt(['action' => 'resumable', '--json' => true])[1], true))->toBe([])
        ->and(yt(['action' => 'mine', 'id' => 'XY-6']))->toBe([0, "MINE XY-6\n"])
        ->and(yt(['action' => 'mine', 'id' => 'XY-12']))->toBe([3, "FOREIGN XY-12\n"]);

    [$status, $output] = yt(['action' => 'claim', 'id' => 'XY-12', '--as' => 'XY-12', '--worktree' => '/w/XY-2', '--branch' => 'XY-2']);

    expect($status)->toBe(3)
        ->and($output)->toContain('LOST: XY-12 was reported by another YouTrack user')
        ->and($server->issues['XY-12']['fields']['Stage'])->toBe('Ready')
        ->and($server->callsOf('add_issue_comment'))->toBe([])
        ->and($server->callsOf('manage_issue_tags'))->toBe([]);
});

it('refuses an unknown Stage', function () {
    epicProject();

    [$status, $output] = yt(['action' => 'release', 'id' => 'XY-6', '--state' => 'Stage']);

    expect($status)->toBe(1)
        ->and($output)->toContain('Stage: Backlog, Analysis, Ready, In Progress, Review, Blocked, On Hold, Done');
});

it('lists claimed epics with their owners and blocked issues with their reasons', function () {
    epicProject()
        ->issue('XY-40', 'Epic', 'In Progress', tags: ['agent-claimed'])
        ->comment('XY-40', "[AGENT:START]\nowner: `host:/w/XY-40`")
        ->issue('XY-41', 'Task', 'Blocked', 'XY-4')
        ->comment('XY-41', "[AGENT:BLOCKED]\n**Что мешает:** нет ключа API.");

    [, $claimed] = yt(['action' => 'claimed-epics', '--json' => true]);
    [, $blocked] = yt(['action' => 'blocked']);

    expect(json_decode($claimed, true))->toBe([['id' => 'XY-40', 'state' => 'In Progress', 'summary' => '[EPIC] Summary of XY-40', 'owner' => 'host:/w/XY-40']])
        ->and($blocked)->toContain("XY-41 [TASK] Summary of XY-41\n    [AGENT:BLOCKED]\n    **Что мешает:** нет ключа API.", 'XY-7 [TASK] Summary of XY-7 <- XY-6 (Ready)');
});

it('prints the state of an issue', function () {
    epicProject()->issue('XY-50', 'Epic', 'Review', summary: '[EPIC] Личные заметки');

    expect(yt(['action' => 'state', 'id' => 'XY-50']))->toBe([0, "Review\n"]);
});

it('prints the knowledge base tree', function () {
    (new FakeYouTrackMcp)
        ->article('XY-A-1', 'Обзор продукта')
        ->article('XY-A-2', 'Системная аналитика')
        ->article('XY-A-3', 'Общие требования', 'XY-A-2')
        ->article('XY-A-12', 'Заметки', 'XY-A-2')
        ->article('XY-A-13', 'Личные заметки', 'XY-A-12')
        ->article('OTHER-A-1', 'Чужая статья')
        ->fake();

    expect(yt(['action' => 'kb-tree'])[1])->toBe(implode("\n", [
        'XY-A-1    Обзор продукта',
        'XY-A-2    Системная аналитика',
        '  XY-A-3    Общие требования',
        '  XY-A-12   Заметки',
        '    XY-A-13   Личные заметки',
    ])."\n")
        ->and(yt(['action' => 'kb-tree', 'id' => 'XY-A-2', '--depth' => '1'])[1])->toBe("XY-A-2    Системная аналитика\n  XY-A-3    Общие требования\n  XY-A-12   Заметки\n")
        ->and(yt(['action' => 'kb-tree', 'id' => 'XY-A-99'])[0])->toBe(1);
});

it('explains what is missing', function () {
    config(['agentio.youtrack.token' => null]);

    expect(yt(['action' => 'ideas']))->toMatchArray([0 => 1])
        ->and(yt(['action' => 'ideas'])[1])->toContain('YOUTRACK_URL and YOUTRACK_TOKEN are not set');

    Http::assertNothingSent();
});

it('rejects unknown actions and missing ids, and prints help', function () {
    epicProject();

    expect(yt(['action' => 'nope'])[0])->toBe(1)
        ->and(yt(['action' => 'tree'])[1])->toContain('This action needs an issue id')
        ->and(yt(['action' => 'tree', 'id' => 'XY-404'])[1])->toContain('Issue XY-404 does not exist')
        ->and(yt([])[1])->toContain('Usage: php artisan agentio:yt <action>', 'claim <ID>');
});
