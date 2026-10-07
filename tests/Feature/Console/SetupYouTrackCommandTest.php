<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Obrazmisli\Agentio\Console\Commands\SetupYouTrackCommand;
use Obrazmisli\Agentio\Install\Installer;
use Obrazmisli\Agentio\Install\KnowledgeBase;
use Obrazmisli\Agentio\Install\Manifest;
use Obrazmisli\Agentio\Install\Placeholders;
use Obrazmisli\Agentio\Install\YouTrackSetup;
use Obrazmisli\Agentio\Tests\Fakes\FakeYouTrackMcp;
use Obrazmisli\Agentio\YouTrack\Client;
use Obrazmisli\Agentio\YouTrack\Mcp\McpClient;

beforeEach(function () {
    Sleep::fake();
    Http::preventStrayRequests();
    config([
        'agentio.youtrack.url' => FakeYouTrackMcp::URL,
        'agentio.youtrack.token' => 'secret-token',
        'agentio.youtrack.project' => 'XY',
    ]);
});

/**
 * Global and project fields of a YouTrack in Russian whose default Stage and Type fields are attached to the
 * project with bundles of its own, optionally with a State field («Состояние») as well. A value is a name, or
 * a name => localized name.
 *
 * @param  array<int|string, string>  $stages
 * @param  array<int|string, string>  $types
 * @return array<string, mixed>
 */
function russianYouTrack(bool $withState = false, array $stages = ['Backlog', 'Analysis', 'Ready', 'In Progress', 'Review', 'Blocked', 'Done'], array $types = ['Idea', 'Epic', 'Story', 'Task']): array
{
    $values = fn (string $prefix, array $values): array => array_map(
        fn (int|string $key, string $value): array => is_int($key)
            ? ['id' => $prefix.$value, 'name' => $value, 'localizedName' => null]
            : ['id' => $prefix.$key, 'name' => $key, 'localizedName' => $value],
        array_keys($values),
        $values,
    );
    $stageValues = $values('s-', $stages);
    $typeValues = $values('t-', $types);

    return [
        'projectFields' => [
            ['id' => 'pf-stage', '$type' => 'StateProjectCustomField', 'field' => ['id' => 'f-stage', 'name' => 'Stage', 'localizedName' => 'Этап', 'fieldType' => ['id' => 'state[1]']], 'bundle' => ['id' => 'b-stage', 'name' => 'XY Stages', '$type' => 'StateBundle'], 'defaultValues' => [['name' => 'Backlog']]],
            ['id' => 'pf-type', '$type' => 'EnumProjectCustomField', 'field' => ['id' => 'f-type', 'name' => 'Type', 'localizedName' => 'Тип', 'fieldType' => ['id' => 'enum[1]']], 'bundle' => ['id' => 'b-type', 'name' => 'XY Types', '$type' => 'EnumBundle'], 'defaultValues' => [['name' => 'Task']]],
            ...($withState ? [['id' => 'pf-state', '$type' => 'StateProjectCustomField', 'field' => ['id' => 'f-state', 'name' => 'State', 'localizedName' => 'Состояние', 'fieldType' => ['id' => 'state[1]']], 'bundle' => ['id' => 'b-state', 'name' => 'XY States', '$type' => 'StateBundle'], 'defaultValues' => [['name' => 'Open']]]] : []),
        ],
        'bundles' => [
            'state' => [['id' => 'b-stage', 'name' => 'XY Stages', 'values' => $stageValues]],
            'enum' => [['id' => 'b-type', 'name' => 'XY Types', 'values' => $typeValues]],
        ],
        'fields' => [
            ['id' => 'f-stage', 'name' => 'Stage', 'localizedName' => 'Этап', 'fieldType' => ['id' => 'state[1]'], 'instances' => [['project' => ['id' => '0-9'], 'bundle' => ['id' => 'b-stage']]]],
            ['id' => 'f-type', 'name' => 'Type', 'localizedName' => 'Тип', 'fieldType' => ['id' => 'enum[1]'], 'instances' => [['project' => ['id' => '0-9'], 'bundle' => ['id' => 'b-type']]]],
        ],
        'tags' => [['id' => '1', 'name' => 'idea'], ['id' => '2', 'name' => 'agent-claimed']],
        'queries' => array_map(fn (string $name, string $query): array => ['name' => $name, 'query' => $query], array_keys(YouTrackSetup::savedSearches('XY')), YouTrackSetup::savedSearches('XY')),
    ];
}

/**
 * The knowledge base of a project set up before, with the guide of this package version.
 */
function existingKnowledgeBase(FakeYouTrackMcp $mcp): void
{
    foreach (KnowledgeBase::ARTICLES as $key => [$title, $parent]) {
        $mcp->article('XY-A-'.(array_search($key, array_keys(KnowledgeBase::ARTICLES), true) + 1), $title, $parent === null ? null : 'XY-A-'.(array_search($parent, array_keys(KnowledgeBase::ARTICLES), true) + 1));
    }

    $kb = [];

    foreach (array_keys(KnowledgeBase::ARTICLES) as $index => $key) {
        $kb[$key] = 'XY-A-'.($index + 1);
    }

    $mcp->articles[$kb[KnowledgeBase::GUIDE]]['content'] = KnowledgeBase::ofPackage()->content(KnowledgeBase::GUIDE, new Placeholders('XY', 'dev', $kb));
}

it('sets up an empty project: Stage and Type values, tags, saved searches and the knowledge base', function () {
    $project = hostProject();
    $mcp = (new FakeYouTrackMcp)->fake();
    fakeYouTrackRestApi();

    $this->artisan('agentio:setup-youtrack', ['--no-interaction' => true])
        ->expectsOutputToContain('Setting up of the YouTrack project XY')
        ->assertSuccessful();

    $writes = implode("\n", restWrites());

    expect($writes)->toContain(
        'POST admin/customFieldSettings/bundles/state {"name":"XY Stages","values":[{"name":"Backlog","isResolved":false},{"name":"Analysis","isResolved":false}',
        'POST admin/customFieldSettings/bundles/enum {"name":"XY Types"',
        'POST admin/customFieldSettings/customFields {"name":"Stage","fieldType":{"id":"state[1]"}',
        'POST admin/customFieldSettings/customFields {"name":"Type","fieldType":{"id":"enum[1]"}',
        'POST tags {"name":"idea"}',
        'POST tags {"name":"agent-claimed"}',
        'POST savedQueries {"name":"XY: готовые эпики","query":"project: XY Type: Epic Stage: Ready tag: -{agent-claimed}"}',
    )->not->toContain('"name":"State"');

    $created = $mcp->callsOf('create_article');
    $titles = array_column($created, 'summary');
    $kb = Manifest::load($project)->kb;

    $expected = array_values(array_diff(array_column(KnowledgeBase::ARTICLES, 0), ['Руководство по автоматизации']));

    expect($titles)->toBe([...$expected, 'Руководство по автоматизации'])
        ->not->toContain('Модель данных')
        ->and($mcp->callsOf('update_article'))->toHaveCount(1)
        ->and($mcp->articles[$kb['guide']]['content'])->toContain('статья **'.$kb['guide'].' «Руководство по автоматизации»**')
        ->and($created[0])->not->toHaveKey('parentArticle')
        ->and($created[array_search('ADR-001: Базовые архитектурные решения', $titles, true)]['parentArticle'])->toBe($kb['adr'])
        ->and($created[array_search('Руководство по автоматизации', $titles, true)]['content'])->toContain('# Руководство по автоматизации разработки', 'проект `XY`')
        ->and($kb)->toHaveCount(count(KnowledgeBase::ARTICLES))
        ->and($kb['overview'])->toBe('XY-A-1');
});

it('uses the default Stage field of the project and creates nothing twice', function () {
    hostProject();
    $mcp = (new FakeYouTrackMcp)->fake();
    existingKnowledgeBase($mcp);
    fakeYouTrackRestApi(russianYouTrack());

    $this->artisan('agentio:setup-youtrack', ['--no-interaction' => true])
        ->expectsOutputToContain('bundle XY Stages, shown as «Этап»')
        ->assertSuccessful();

    expect(restWrites())->toBe([])
        ->and($mcp->callsOf('create_article'))->toBe([])
        ->and($mcp->callsOf('update_article'))->toBe([]);
});

it('adds the missing values to the Stage field of the project and removes the values of YouTrack the cycle does not use', function () {
    hostProject();
    (new FakeYouTrackMcp)->fake();
    fakeYouTrackRestApi(russianYouTrack(stages: ['Backlog', 'Develop', 'Review', 'Test', 'Staging', 'Done', 'On hold']));

    $this->artisan('agentio:setup-youtrack', ['--no-interaction' => true])
        ->expectsOutputToContain('add value Analysis')
        ->expectsOutputToContain('remove value Develop')
        ->assertSuccessful();

    $added = array_values(array_filter(restWrites(), fn (string $write): bool => str_starts_with($write, 'POST admin/customFieldSettings/bundles/state/b-stage/values')));
    $removed = array_values(array_filter(restWrites(), fn (string $write): bool => str_starts_with($write, 'DELETE ')));

    expect($added)->toHaveCount(4)
        ->and(implode("\n", $added))->toContain('"name":"Analysis"', '"name":"Ready"', '"name":"In Progress"', '"name":"Blocked"')
        ->and($removed)->toBe([
            'DELETE admin/customFieldSettings/bundles/state/b-stage/values/s-Develop []',
            'DELETE admin/customFieldSettings/bundles/state/b-stage/values/s-Test []',
            'DELETE admin/customFieldSettings/bundles/state/b-stage/values/s-Staging []',
        ])
        ->and(implode("\n", restWrites()))->not->toContain('customFieldSettings/customFields {', 'bundles/state {', 'On hold');
});

it('keeps a Stage value of YouTrack that issues of the project are in and reports it', function () {
    hostProject();
    $mcp = (new FakeYouTrackMcp)->fake();
    existingKnowledgeBase($mcp);
    fakeYouTrackRestApi([
        ...russianYouTrack(stages: ['Backlog', 'Analysis', 'Ready', 'In Progress', 'Review', 'Blocked', 'Done', 'Develop', 'Test']),
        'issues' => fn (string $query): array => $query === 'project: XY Stage: {Test}' ? [['id' => '2-1']] : [],
    ]);

    $this->artisan('agentio:setup-youtrack', ['--no-interaction' => true])
        ->expectsOutputToContain('value Test is not used by the cycle but issues of the project are in it, so it is kept')
        ->assertSuccessful();

    expect(restWrites())->toBe(['DELETE admin/customFieldSettings/bundles/state/b-stage/values/s-Develop []']);
});

it('never removes values from a Stage bundle shared with other projects', function () {
    hostProject();
    $mcp = (new FakeYouTrackMcp)->fake();
    existingKnowledgeBase($mcp);
    $youtrack = russianYouTrack(stages: ['Backlog', 'Analysis', 'Ready', 'In Progress', 'Review', 'Blocked', 'Done', 'Develop']);
    $youtrack['fields'][0]['instances'][] = ['project' => ['id' => '0-5'], 'bundle' => ['id' => 'b-stage']];
    fakeYouTrackRestApi([...$youtrack, 'issues' => [['id' => '2-1']]]);

    $this->artisan('agentio:setup-youtrack', ['--no-interaction' => true])
        ->expectsOutputToContain('is shared with other projects')
        ->assertSuccessful();

    expect(restWrites())->toBe([]);
});

it('renames a Stage field named in Russian to Stage and attaches it instead of creating a second one', function () {
    hostProject();
    (new FakeYouTrackMcp)->fake();
    $youtrack = russianYouTrack();
    $youtrack['projectFields'] = [$youtrack['projectFields'][1]];
    $youtrack['fields'][0] = ['id' => 'f-etap', 'name' => 'Этап', 'localizedName' => null, 'fieldType' => ['id' => 'state[1]'], 'instances' => []];
    fakeYouTrackRestApi($youtrack);

    $this->artisan('agentio:setup-youtrack', ['--no-interaction' => true])
        ->expectsOutputToContain('rename the field «Этап» to Stage')
        ->assertSuccessful();

    $writes = implode("\n", restWrites());

    expect($writes)->toContain(
        'POST admin/customFieldSettings/customFields/f-etap {"name":"Stage"}',
        'POST admin/projects/0-9/customFields {"$type":"StateProjectCustomField","field":{"id":"f-etap"}',
    )->not->toContain('customFieldSettings/customFields {', 'bundles/state {');
});

it('renames a field of the project named in Russian', function () {
    hostProject();
    $mcp = (new FakeYouTrackMcp)->fake();
    existingKnowledgeBase($mcp);
    $youtrack = russianYouTrack();
    $youtrack['projectFields'][1]['field'] = ['id' => 'f-type', 'name' => 'Тип', 'localizedName' => null, 'fieldType' => ['id' => 'enum[1]']];
    $youtrack['fields'][1] = [...$youtrack['fields'][1], 'name' => 'Тип', 'localizedName' => null];
    fakeYouTrackRestApi($youtrack);

    $this->artisan('agentio:setup-youtrack', ['--no-interaction' => true])
        ->expectsOutputToContain('rename the field «Тип» to Type')
        ->assertSuccessful();

    expect(restWrites())->toBe(['POST admin/customFieldSettings/customFields/f-type {"name":"Type"}']);
});

it('shows the values YouTrack localized by their English names, so the MCP server answers with them', function () {
    hostProject();
    $mcp = (new FakeYouTrackMcp)->fake();
    existingKnowledgeBase($mcp);
    fakeYouTrackRestApi(russianYouTrack(
        stages: ['Backlog' => 'Очередь', 'Develop' => 'Разработка', 'Analysis', 'Ready', 'In Progress', 'Review' => 'Ревью', 'Blocked', 'Done' => 'Готово'],
        types: ['Idea', 'Epic', 'Story', 'Task' => 'Задание'],
    ));

    $this->artisan('agentio:setup-youtrack', ['--no-interaction' => true])
        ->expectsOutputToContain('show value Backlog by its name instead of «Очередь»')
        ->assertSuccessful();

    expect(restWrites())->toBe([
        'POST admin/customFieldSettings/bundles/state/b-stage/values/s-Backlog {"localizedName":null}',
        'POST admin/customFieldSettings/bundles/state/b-stage/values/s-Review {"localizedName":null}',
        'POST admin/customFieldSettings/bundles/state/b-stage/values/s-Done {"localizedName":null}',
        'DELETE admin/customFieldSettings/bundles/state/b-stage/values/s-Develop []',
        'POST admin/customFieldSettings/bundles/enum/b-type/values/t-Task {"localizedName":null}',
    ]);
});

it('renames the values named in Russian to the names of the cycle instead of adding them next to them', function () {
    hostProject();
    $mcp = (new FakeYouTrackMcp)->fake();
    existingKnowledgeBase($mcp);
    $youtrack = russianYouTrack(stages: ['Очередь', 'Разработка', 'В работе', 'Готово'], types: ['Эпик', 'Задача', 'Ошибка']);
    $youtrack['projectFields'][0]['defaultValues'] = [['name' => 'Очередь']];
    fakeYouTrackRestApi($youtrack);

    $this->artisan('agentio:setup-youtrack', ['--no-interaction' => true])
        ->expectsOutputToContain('rename value «Очередь» to Backlog')
        ->assertSuccessful();

    $writes = restWrites();

    $rename = fn (string $kind, string $bundle, string $value, string $name): string => "POST admin/customFieldSettings/bundles/{$kind}/{$bundle}/values/".rawurlencode($value).' {"name":"'.$name.'","localizedName":null}';

    expect($writes)->toContain(
        $rename('state', 'b-stage', 's-Очередь', 'Backlog'),
        $rename('state', 'b-stage', 's-В работе', 'In Progress'),
        $rename('state', 'b-stage', 's-Готово', 'Done'),
        $rename('enum', 'b-type', 't-Эпик', 'Epic'),
        $rename('enum', 'b-type', 't-Задача', 'Task'),
        'POST admin/projects/0-9/customFields/pf-stage {"$type":"StateProjectCustomField","defaultValues":[{"id":"s-Очередь","$type":"StateBundleElement"}]}',
    )->and(implode("\n", $writes))->toContain('values {"name":"Analysis"', 'values {"name":"Idea"')
        ->not->toContain('values {"name":"Backlog"', 'values {"name":"Done"', 'values {"name":"Task"', 'Разработка', 'Ошибка');
});

it('reports a State field it does not use and leaves it as is', function () {
    hostProject();
    $mcp = (new FakeYouTrackMcp)->fake();
    existingKnowledgeBase($mcp);
    fakeYouTrackRestApi(russianYouTrack(withState: true));

    $this->artisan('agentio:setup-youtrack', ['--no-interaction' => true])
        ->expectsOutputToContain('not used by agentio: the cycle keeps the status of an issue in Stage')
        ->assertSuccessful();

    expect(restWrites())->toBe([]);
});

it('gives a new project bundles of its own instead of changing the shared default bundles', function () {
    hostProject();
    (new FakeYouTrackMcp)->fake();
    fakeYouTrackRestApi([
        'projectFields' => [
            ['id' => 'pf-stage', '$type' => 'StateProjectCustomField', 'field' => ['name' => 'Stage', 'localizedName' => null], 'bundle' => ['id' => 'b-default', 'name' => 'Stages', '$type' => 'StateBundle']],
        ],
        'bundles' => ['state' => [['id' => 'b-default', 'name' => 'Stages', 'values' => [['name' => 'Backlog'], ['name' => 'Develop'], ['name' => 'Done']]]], 'enum' => []],
        'fields' => [
            ['id' => 'f-stage', 'name' => 'Stage', 'localizedName' => null, 'fieldType' => ['id' => 'state[1]'], 'fieldDefaults' => ['bundle' => ['id' => 'b-default']], 'instances' => [['project' => ['id' => '0-9'], 'bundle' => ['id' => 'b-default']]]],
        ],
    ]);

    $this->artisan('agentio:setup-youtrack', ['--no-interaction' => true])
        ->expectsOutputToContain('switch from the shared bundle Stages to XY Stages')
        ->assertSuccessful();

    $writes = implode("\n", restWrites());

    expect($writes)->not->toContain('b-default/values')
        ->toContain(
            'POST admin/projects/0-9/customFields/pf-stage {"$type":"StateProjectCustomField","defaultValues":[],"canBeEmpty":true}',
            '"defaultValues":[{"id":"val-Backlog","$type":"StateBundleElement"}],"canBeEmpty":false}',
        );
});

it('makes new issues start in Backlog when the default of Stage is not one of the cycle values', function () {
    hostProject();
    (new FakeYouTrackMcp)->fake();
    $youtrack = russianYouTrack(stages: ['Backlog', 'Analysis', 'Ready', 'In Progress', 'Review', 'Blocked', 'Done', 'Submitted']);
    $youtrack['projectFields'][0]['defaultValues'] = [['name' => 'Submitted']];
    fakeYouTrackRestApi($youtrack);

    $this->artisan('agentio:setup-youtrack', ['--no-interaction' => true])
        ->expectsOutputToContain('default value Submitted → Backlog')
        ->assertSuccessful();

    expect(restWrites())->toContain('POST admin/projects/0-9/customFields/pf-stage {"$type":"StateProjectCustomField","defaultValues":[{"id":"s-Backlog","$type":"StateBundleElement"}]}');
});

it('accepts a board of the project with columns by Stage and swimlanes by Type, named in Russian too', function () {
    hostProject();
    $mcp = (new FakeYouTrackMcp)->fake();
    existingKnowledgeBase($mcp);
    $other = agileBoard('Other');
    $other['projects'] = [['id' => '0-5', 'shortName' => 'AB']];
    fakeYouTrackRestApi([...russianYouTrack(), 'agiles' => [$other, agileBoard('XY Kanban', 'State', null), agileBoard('Доска XY', 'Этап', 'Тип')]]);

    $this->artisan('agentio:setup-youtrack', ['--no-interaction' => true])
        ->expectsOutputToContain('columns by Stage, swimlanes by Type')
        ->assertSuccessful();

    expect(restWrites())->toBe([]);
});

it('fails when the project has no board with columns by Stage and swimlanes by Type, after setting up the rest', function () {
    $project = hostProject();
    $mcp = (new FakeYouTrackMcp)->fake();
    fakeYouTrackRestApi([...russianYouTrack(), 'agiles' => [agileBoard('XY Kanban', 'State', null), agileBoard('XY Stages', 'Stage', 'Priority')]]);

    $this->artisan('agentio:setup-youtrack', ['--no-interaction' => true])
        ->expectsOutputToContain('board XY: no board of the project')
        ->expectsOutputToContain('«XY Kanban»: columns by State instead of Stage, no swimlanes by a field; «XY Stages»: swimlanes by Priority instead of Type')
        ->assertExitCode(SetupYouTrackCommand::INCOMPLETE);

    expect(Manifest::load($project)->kb)->toHaveCount(count(KnowledgeBase::ARTICLES))
        ->and($mcp->callsOf('create_article'))->not->toBe([]);
});

it('fails when the project has no board at all, in a dry run too', function () {
    hostProject();
    (new FakeYouTrackMcp)->fake();
    fakeYouTrackRestApi([...russianYouTrack(), 'agiles' => []]);

    $this->artisan('agentio:setup-youtrack', ['--no-interaction' => true, '--dry-run' => true])
        ->expectsOutputToContain('the project has no agile board')
        ->assertExitCode(SetupYouTrackCommand::INCOMPLETE);

    expect(restWrites())->toBe([]);
});

it('warns about the stages of the cycle that have no column on the board', function () {
    hostProject();
    $mcp = (new FakeYouTrackMcp)->fake();
    existingKnowledgeBase($mcp);
    fakeYouTrackRestApi([...russianYouTrack(), 'agiles' => [agileBoard(columns: ['Backlog', 'Ready', 'In Progress', 'Review', 'Done'])]]);

    $this->artisan('agentio:setup-youtrack', ['--no-interaction' => true])
        ->expectsOutputToContain('no column for Analysis, Blocked')
        ->assertSuccessful();
});

it('keeps existing articles, prefers the one under the expected parent and refreshes the automation guide', function () {
    $project = hostProject();
    $mcp = (new FakeYouTrackMcp)
        ->article('XY-A-1', 'Обзор продукта', content: 'Наш продукт')
        ->article('XY-A-2', 'Системная аналитика')
        ->article('XY-A-3', '1. Бизнес-контекст и цели', 'XY-A-2')
        ->article('XY-A-4', 'Процесс разработки')
        ->article('XY-A-5', 'ADR')
        ->article('XY-A-6', 'Руководство по автоматизации', 'XY-A-4', content: 'Старое руководство')
        ->fake();
    fakeYouTrackRestApi(russianYouTrack());

    $this->artisan('agentio:setup-youtrack', ['--no-interaction' => true])
        ->expectsOutputToContain('the manual of the installed agentio version')
        ->assertSuccessful();

    $kb = Manifest::load($project)->kb;

    expect($mcp->articles['XY-A-1']['content'])->toBe('Наш продукт')
        ->and($mcp->articles['XY-A-6']['content'])->toContain('# Руководство по автоматизации разработки')
        ->and($kb['guide'])->toBe('XY-A-6')
        ->and($kb['analysis'])->toBe('XY-A-2')
        ->and($mcp->articles[$kb['analysis.common']]['parent'])->toBe('XY-A-2')
        ->and($kb['adr'])->toBe('XY-A-5')
        ->and($mcp->articles['XY-A-5']['parent'])->toBeNull()
        ->and($mcp->articles[$kb['adr.001']]['parent'])->toBe('XY-A-5')
        ->and($mcp->articles)->toHaveKey('XY-A-3');
});

it('records the knowledge base ids in the installed skills', function () {
    $project = hostProject();
    (new FakeYouTrackMcp)->fake();
    fakeYouTrackRestApi(russianYouTrack());
    $installer = new Installer($project, dirname(__DIR__, 3).'/stubs', new Placeholders('XY', 'main'));
    $installer->install();
    (new Manifest('XY', 'main', files: $installer->hashes()))->save($project);

    $this->artisan('agentio:setup-youtrack', ['--no-interaction' => true])
        ->expectsOutputToContain('.claude/skills/agentio-youtrack-workflow/SKILL.md')
        ->expectsOutputToContain('Knowledge base ids recorded in .agentio.json and in the installed skills.')
        ->assertSuccessful();

    $manifest = Manifest::load($project);

    expect(file_get_contents($project.'/.claude/skills/agentio-youtrack-workflow/SKILL.md'))->toContain('| Обзор продукта | '.$manifest->kb['overview'].' |')
        ->and($manifest->files['.claude/skills/agentio-youtrack-workflow/SKILL.md'])->toBe(hash_file('sha256', $project.'/.claude/skills/agentio-youtrack-workflow/SKILL.md'));
});

it('only plans in a dry run', function () {
    $project = hostProject();
    $mcp = (new FakeYouTrackMcp)->fake();
    fakeYouTrackRestApi();

    $this->artisan('agentio:setup-youtrack', ['--no-interaction' => true, '--dry-run' => true])
        ->expectsOutputToContain('will create')
        ->assertSuccessful();

    expect(restWrites())->toBe([])
        ->and($mcp->callsOf('create_article'))->toBe([])
        ->and(Manifest::exists($project))->toBeFalse();
});

it('fails when YouTrack is not configured or the project does not exist', function () {
    hostProject();
    config(['agentio.youtrack.token' => null]);

    $this->artisan('agentio:setup-youtrack', ['--no-interaction' => true])
        ->expectsOutputToContain('run php artisan agentio:install first')
        ->assertFailed();

    config(['agentio.youtrack.token' => 'secret-token']);
    app()->forgetInstance(Client::class);
    app()->forgetInstance(McpClient::class);
    (new FakeYouTrackMcp)->fake();

    $this->artisan('agentio:setup-youtrack', ['--no-interaction' => true, '--project' => 'NOPE'])
        ->expectsOutputToContain('The YouTrack project NOPE does not exist')
        ->assertFailed();
});
