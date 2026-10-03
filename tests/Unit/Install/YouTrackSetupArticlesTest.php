<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Obrazmisli\Agentio\Install\KnowledgeBase;
use Obrazmisli\Agentio\Install\Placeholders;
use Obrazmisli\Agentio\Install\YouTrackSetup;
use Obrazmisli\Agentio\Runtime\MergePolicy;
use Obrazmisli\Agentio\YouTrack\Client;

/**
 * A fake YouTrack whose project "XY" already has the given articles; every other entity is missing and
 * any write succeeds. Created articles get the ids "186-<n>" / "XY-A-<n>" after the existing ones.
 *
 * @param  list<array<string, mixed>>  $articles
 */
function fakeKnowledgeBase(array $articles): void
{
    Http::fake(function (Request $request) use (&$articles) {
        $path = (string) preg_replace('#^https://yt\.example\.com/api/#', '', strtok($request->url(), '?') ?: '');
        $get = $request->method() === 'GET';
        $page = fn (array $items) => Http::response((int) ($request['$skip'] ?? 0) > 0 ? [] : array_values($items));

        return match (true) {
            $get && $path === 'admin/projects' => $page([['id' => '0-9', 'shortName' => 'XY']]),
            $get && $path === 'articles' => $page($articles),
            $get => $page([]),
            $path === 'articles' => (function () use (&$articles, $request) {
                $number = count($articles) + 1;
                $article = [
                    'id' => '186-'.$number,
                    'idReadable' => 'XY-A-'.$number,
                    'summary' => $request['summary'],
                    'project' => ['shortName' => 'XY'],
                    'parentArticle' => $request['parentArticle'] ?? null,
                ];
                $articles[] = $article;

                return Http::response($article);
            })(),
            default => Http::response(['id' => 'new', 'values' => []]),
        };
    });
}

/**
 * @return array<string, mixed>
 */
function existingArticle(int $number, string $summary, ?int $parent = null): array
{
    return [
        'id' => '186-'.$number,
        'idReadable' => 'XY-A-'.$number,
        'summary' => $summary,
        'project' => ['shortName' => 'XY'],
        'parentArticle' => $parent === null ? null : ['id' => '186-'.$parent, 'idReadable' => 'XY-A-'.$parent],
    ];
}

/**
 * @return array<string, string>
 */
function runKnowledgeBaseSetup(): array
{
    $setup = new YouTrackSetup(new Client('https://yt.example.com', 'secret-token'), new KnowledgeBase(dirname(__DIR__, 3).'/stubs'));

    return $setup->run('XY', new Placeholders('XY', 'main', MergePolicy::LocalBranch));
}

/**
 * @return list<Request>
 */
function createdArticles(): array
{
    return Http::recorded(fn (Request $request): bool => $request->method() === 'POST' && str_contains($request->url(), '/api/articles'))
        ->map(fn (array $pair): Request => $pair[0])
        ->values()
        ->all();
}

it('creates the analysis root with the common requirements only, no articles per layer', function () {
    fakeKnowledgeBase([]);

    $kb = runKnowledgeBaseSetup();
    $created = collect(createdArticles())->keyBy(fn (Request $request): string => (string) $request['summary']);

    expect(KnowledgeBase::ARTICLES)->toHaveKey('analysis.common')
        ->and(array_values(array_filter(array_keys(KnowledgeBase::ARTICLES), fn (string $key): bool => str_starts_with($key, 'analysis.'))))->toBe(['analysis.common'])
        ->and($created->keys()->all())->toBe(array_column(KnowledgeBase::ARTICLES, 0))
        ->and($created['Общие требования']['parentArticle'])->toBe(['id' => '186-2'])
        ->and($created['Общие требования']['content'])->toContain('## Роли и права', '## Внешние системы')
        ->and($created['Системная аналитика']['content'])->toContain('## Карта модулей', '| Модуль | Статья | Фичи | Назначение |')
        ->and($created['Системная аналитика']['content'])->not->toContain('Бизнес-контекст')
        ->and($kb['analysis.common'])->toBe('XY-A-3');
});

it('adds the common requirements to an analysis tree of an older version and leaves the layer articles alone', function () {
    $layers = ['1. Бизнес-контекст и цели', '2. Пользователи, роли и сценарии', '3. Функциональные требования и бизнес-правила', '4. Данные: сущности, атрибуты, связи, жизненный цикл', '5. Интеграции и интерфейсы', '6. Нефункциональные требования', '7. Ограничения, допущения и риски'];
    $articles = [existingArticle(1, 'Обзор продукта'), existingArticle(2, 'Системная аналитика')];

    foreach ($layers as $index => $layer) {
        $articles[] = existingArticle(3 + $index, $layer, 2);
    }

    foreach (array_slice(array_keys(KnowledgeBase::ARTICLES), 3) as $index => $key) {
        $parent = KnowledgeBase::ARTICLES[$key][1];
        $articles[] = existingArticle(10 + $index, KnowledgeBase::ARTICLES[$key][0], $parent === null ? null : 10 + (int) array_search($parent, array_slice(array_keys(KnowledgeBase::ARTICLES), 3), true));
    }

    fakeKnowledgeBase($articles);

    $kb = runKnowledgeBaseSetup();
    $writes = collect(Http::recorded(fn (Request $request): bool => $request->method() !== 'GET' && str_contains($request->url(), '/api/articles')));

    expect($writes)->toHaveCount(1)
        ->and(createdArticles()[0]['summary'])->toBe('Общие требования')
        ->and(createdArticles()[0]['parentArticle'])->toBe(['id' => '186-2'])
        ->and($kb['analysis'])->toBe('XY-A-2')
        ->and($kb['analysis.common'])->toBe('XY-A-'.(count($articles) + 1))
        ->and($kb)->not->toHaveKey('analysis.business');
});

it('prefers an existing article under its expected parent when titles repeat', function () {
    fakeKnowledgeBase([
        existingArticle(1, 'Системная аналитика'),
        existingArticle(2, 'Платежи', 1),
        existingArticle(3, 'Общие требования', 2),
        existingArticle(4, 'Общие требования', 1),
    ]);

    $kb = runKnowledgeBaseSetup();

    expect($kb['analysis'])->toBe('XY-A-1')
        ->and($kb['analysis.common'])->toBe('XY-A-4')
        ->and(collect(createdArticles())->pluck('summary'))->not->toContain('Общие требования', 'Системная аналитика');
});
