<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * The yt.php stub rendered for project XY in a temporary directory, talking to a local fake of
 * GET /api/articles (PHP built-in server) that answers with the given articles.
 *
 * @param  list<array<string, mixed>>  $articles
 * @return array{0: string, 1: string} The script path and the base URL of the fake
 */
function ytWithArticles(array $articles): array
{
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'agentio-kb-'.Str::random(12);
    mkdir($directory.'/scripts', 0777, true);
    test()->beforeApplicationDestroyed(fn () => (new Filesystem)->deleteDirectory($directory));

    $script = (string) file_get_contents(dirname(__DIR__, 3).'/stubs/scripts/yt.php');
    file_put_contents($directory.'/scripts/yt.php', str_replace('{{project}}', 'XY', $script));
    file_put_contents($directory.'/articles.json', json_encode($articles, JSON_THROW_ON_ERROR));
    file_put_contents($directory.'/router.php', <<<'PHP'
        <?php
        $url = parse_url($_SERVER['REQUEST_URI']);
        parse_str($url['query'] ?? '', $query);
        file_put_contents(__DIR__.'/requests.log', $_SERVER['REQUEST_URI'].PHP_EOL, FILE_APPEND);
        header('Content-Type: application/json');

        if ($url['path'] === '/api/articles' && ($query['query'] ?? '') === 'project: XY') {
            echo (int) ($query['$skip'] ?? 0) > 0 ? '[]' : file_get_contents(__DIR__.'/articles.json');

            return true;
        }

        http_response_code(404);
        echo '{"error":"not found"}';
        PHP);

    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $address = $socket === false ? '' : (string) stream_socket_get_name($socket, false);

    if ($socket !== false) {
        fclose($socket);
    }

    $server = new Process([PHP_BINARY, '-S', $address, $directory.'/router.php'], $directory);
    $server->start();
    test()->beforeApplicationDestroyed(fn () => $server->stop(0));

    [$host, $port] = explode(':', $address) + ['', '0'];

    for ($attempt = 0; $attempt < 100 && @fsockopen($host, (int) $port) === false; $attempt++) {
        usleep(50_000);
    }

    return [$directory.'/scripts/yt.php', 'http://'.$address];
}

function runYt(string $script, string $url, string ...$arguments): Process
{
    $process = new Process([PHP_BINARY, $script, ...$arguments], dirname($script, 2), [
        'YOUTRACK_URL' => $url,
        'YOUTRACK_TOKEN' => 'secret',
        'AGENTIO_PROJECT' => 'XY',
    ], null, 30);
    $process->run();

    return $process;
}

/**
 * @param  list<string>  $children
 * @return array<string, mixed>
 */
function kbArticle(string $id, string $summary, ?string $parent = null, array $children = [], string $project = 'XY'): array
{
    return [
        'idReadable' => $id,
        'summary' => $summary,
        'project' => ['shortName' => $project],
        'parentArticle' => $parent === null ? null : ['idReadable' => $parent],
        'childArticles' => array_map(fn (string $child): array => ['idReadable' => $child], $children),
    ];
}

beforeEach(function () {
    if (! function_exists('curl_init')) {
        $this->markTestSkipped('the curl extension is not available');
    }

    [$this->script, $this->url] = ytWithArticles([
        kbArticle('XY-A-10', 'Архитектура'),
        kbArticle('XY-A-31', 'Профиль и аватар', 'XY-A-22'),
        kbArticle('XY-A-2', 'Системная аналитика', null, ['XY-A-3', 'XY-A-22']),
        kbArticle('XY-A-24', 'Регистрация', 'XY-A-22'),
        kbArticle('XY-A-22', 'Пользователи', 'XY-A-2', ['XY-A-24']),
        kbArticle('XY-A-3', 'Общие требования', 'XY-A-2'),
        kbArticle('XY-A-1', 'Обзор продукта'),
        kbArticle('ZZ-A-1', 'Чужая статья', null, [], 'ZZ'),
    ]);
});

it('prints the knowledge base tree of the project with article ids', function () {
    $process = runYt($this->script, $this->url, 'kb-tree');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())->toBe(implode(PHP_EOL, [
            'XY-A-1    Обзор продукта',
            'XY-A-2    Системная аналитика',
            '  XY-A-3    Общие требования',
            '  XY-A-22   Пользователи',
            '    XY-A-24   Регистрация',
            '    XY-A-31   Профиль и аватар',
            'XY-A-10   Архитектура',
        ]).PHP_EOL);
});

it('prints a branch of the tree as JSON, limited in depth', function () {
    $process = runYt($this->script, $this->url, 'kb-tree', 'XY-A-2', '--depth=1', '--json');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(json_decode($process->getOutput(), true))->toBe([[
            'id' => 'XY-A-2',
            'summary' => 'Системная аналитика',
            'children' => [
                ['id' => 'XY-A-3', 'summary' => 'Общие требования', 'children' => []],
                ['id' => 'XY-A-22', 'summary' => 'Пользователи', 'children' => []],
            ],
        ]]);
});

it('fails for an article outside the knowledge base of the project', function () {
    $process = runYt($this->script, $this->url, 'kb-tree', 'ZZ-A-1');

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('Article ZZ-A-1 is not in the knowledge base of project XY.');
});
