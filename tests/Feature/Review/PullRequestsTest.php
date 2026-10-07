<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Obrazmisli\Agentio\Review\Release;
use Obrazmisli\Agentio\Review\ReviewException;
use Obrazmisli\Agentio\Runtime\LoopState;
use Obrazmisli\Agentio\Telegram\Conversation;
use Obrazmisli\Agentio\Telegram\IncomingMessage;
use Obrazmisli\Agentio\Telegram\Jobs\SendTelegramMessage;
use Obrazmisli\Agentio\Telegram\Responder;
use Obrazmisli\Agentio\Tests\Fakes\FakeGitHub;
use Obrazmisli\Agentio\Tests\Fakes\FakeYouTrackMcp;

/**
 * A host project that is a git repository with main, dev (two epics merged into it) and the branch of epic XY-2
 * (one commit), whose origin on a fake GitHub has main and dev; YouTrack knows the epic XY-2.
 */
function repositoryWithOrigin(): FakeGitHub
{
    $project = hostProject();
    git($project, 'init', '-q', '-b', 'main');

    foreach (['user.email' => 'dev@example.com', 'user.name' => 'Dev', 'commit.gpgsign' => 'false'] as $key => $value) {
        git($project, 'config', $key, $value);
    }

    file_put_contents($project.'/README.md', "# App\n");
    git($project, 'add', '.');
    git($project, 'commit', '-q', '-m', 'Initial');
    git($project, 'checkout', '-q', '-b', 'dev');

    foreach (['XY-10' => 'Profile', 'XY-20' => 'Avatar'] as $epic => $file) {
        git($project, 'checkout', '-q', '-b', $epic);
        file_put_contents($project.'/'.$file.'.php', "<?php\n");
        git($project, 'add', '.');
        git($project, 'commit', '-q', '-m', "XY-1{$epic[3]}: Add {$file}");
        git($project, 'checkout', '-q', 'dev');
        git($project, 'merge', '-q', '--no-ff', '-m', "Merge pull request from acme/{$epic}", $epic);
    }

    git($project, 'checkout', '-q', '-b', 'XY-2');
    file_put_contents($project.'/Notes.php', "<?php\n");
    git($project, 'add', '.');
    git($project, 'commit', '-q', '-m', 'XY-5: Add notes');
    git($project, 'checkout', '-q', 'main');

    Http::preventStrayRequests();
    (new FakeYouTrackMcp)->issue('XY-2', 'Epic', 'Review', summary: '[EPIC] Заметки')->fake();
    config([
        'agentio.youtrack.url' => FakeYouTrackMcp::URL,
        'agentio.youtrack.token' => 'secret-token',
        'agentio.youtrack.project' => 'XY',
        'agentio.base_branch' => 'dev',
        'agentio.production_branch' => 'main',
    ]);

    return FakeGitHub::originOf($project, 'main', 'dev')->fake();
}

it('publishes an epic: pushes its branch and opens its pull request into the development branch once', function () {
    $github = repositoryWithOrigin();
    $project = base_path();

    $this->artisan('agentio:pr', ['epic' => 'XY-2'])
        ->expectsOutputToContain('XY-2: pull request #1 into dev opened.')
        ->expectsOutputToContain('https://github.com/acme/app/pull/1')
        ->assertSuccessful();

    expect($github->head('XY-2'))->toBe(git($project, 'rev-parse', 'XY-2'))
        ->and($github->pullRequests[1])->toMatchArray(['title' => 'XY-2: Заметки', 'headRefName' => 'XY-2', 'baseRefName' => 'dev', 'state' => 'OPEN'])
        ->and($github->pullRequests[1]['body'])->toContain('Эпик [XY-2](https://yt.example.com/issue/XY-2) «Заметки»', '**Коммиты** (1):', 'XY-5: Add notes', '«смержи XY-2»');

    // After rework: the new commit goes to the open pull request, no second one is opened.
    git($project, 'checkout', '-q', 'XY-2');
    file_put_contents($project.'/Notes.php', "<?php\n// v2\n");
    git($project, 'commit', '-q', '-am', 'XY-6: Fix notes');

    $this->artisan('agentio:pr', ['epic' => 'XY-2', '--json' => true])
        ->expectsOutputToContain('"url":"https://github.com/acme/app/pull/1","state":"OPEN"')
        ->assertSuccessful();

    expect($github->head('XY-2'))->toBe(git($project, 'rev-parse', 'XY-2'))
        ->and($github->pullRequests)->toHaveCount(1)
        ->and($github->callsOf('pr create'))->toHaveCount(1);
});

it('does not publish an epic without GitHub or without anything to merge', function () {
    $github = repositoryWithOrigin();
    $github->authenticated = false;

    $this->artisan('agentio:pr', ['epic' => 'XY-2'])
        ->expectsOutputToContain('XY-2: GitHub CLI (gh) is not logged in: run gh auth login.')
        ->assertFailed();

    $github->authenticated = true;
    git(base_path(), 'branch', '-f', 'XY-2', 'dev');

    $this->artisan('agentio:pr', ['epic' => 'XY-2'])
        ->expectsOutputToContain('Всё из ветки XY-2 уже есть в dev')
        ->assertFailed();

    $this->artisan('agentio:pr', ['epic' => 'XY-7'])->expectsOutputToContain('В главном каталоге нет ветки XY-7.')->assertFailed();

    expect($github->pullRequests)->toBe([]);
});

it('opens the release pull request and merges it only at the confirmed commit', function () {
    $github = repositoryWithOrigin();
    $project = base_path();
    $release = app(Release::class);

    $prepared = $release->prepare();

    expect($prepared)->toMatchArray(['created' => true, 'head' => $github->head('dev'), 'commits' => 4, 'unpushed' => 0])
        ->and($prepared['pullRequest']->toArray())->toMatchArray(['number' => 1, 'head' => 'dev', 'base' => 'main', 'state' => 'OPEN', 'title' => 'Релиз '.date('Y-m-d').': dev → main'])
        ->and($github->pullRequests[1]['body'])->toContain('Merge pull request from acme/XY-10', 'Merge pull request from acme/XY-20', 'после его подтверждения')
        ->and($release->prepare())->toMatchArray(['created' => false])
        ->and($github->callsOf('pr create'))->toHaveCount(1);

    $merged = $release->merge(1, $prepared['head']);

    expect($merged['commit'])->toBe(substr((string) $github->head('main'), 0, 7))
        ->and($merged['warnings'])->toBe([])
        ->and($github->subject('main'))->toBe('Merge pull request #1 from acme/dev')
        ->and($github->pullRequests[1]['state'])->toBe('MERGED')
        // The main checkout is on main with nothing uncommitted: it follows origin.
        ->and(git($project, 'rev-parse', 'main'))->toBe($github->head('main'))
        ->and(file_exists($project.'/Avatar.php'))->toBeTrue();

    expect(fn () => $release->merge(1, $prepared['head']))->toThrow(ReviewException::class, 'Pull request #1 уже слит');
});

it('asks again when the development branch moved after the confirmation was asked', function () {
    $github = repositoryWithOrigin();
    $project = base_path();
    $release = app(Release::class);
    $prepared = $release->prepare();

    git($project, 'checkout', '-q', 'dev');
    file_put_contents($project.'/Late.php', "<?php\n");
    git($project, 'add', '.');
    git($project, 'commit', '-q', '-m', 'Merge pull request from acme/XY-30');
    git($project, 'push', '-q', 'origin', 'dev');

    try {
        $release->merge(1, $prepared['head']);
        $this->fail('The release was merged at a commit nobody confirmed.');
    } catch (ReviewException $exception) {
        expect($exception->status)->toBe(Release::MOVED)
            ->and($exception->getMessage())->toContain('подтвердите релиз заново');
    }

    expect($github->callsOf('api'))->toBe([])
        ->and($github->pullRequests[1]['state'])->toBe('OPEN');
});

it('has nothing to release when main has everything of dev', function () {
    $github = repositoryWithOrigin();
    git(base_path(), 'push', '-q', 'origin', 'dev:main');

    expect(fn () => app(Release::class)->prepare())->toThrow(ReviewException::class, 'выпускать нечего');
    expect($github->pullRequests)->toBe([]);
});

it('releases from the command line after the confirmation', function () {
    $github = repositoryWithOrigin();

    $this->artisan('agentio:release')
        ->expectsOutputToContain('Release pull request #1 (dev → main, 4 commits) opened')
        ->expectsOutputToContain('php artisan agentio:release --merge')
        ->assertSuccessful();

    expect($github->pullRequests[1]['state'])->toBe('OPEN');

    $this->artisan('agentio:release', ['--merge' => true])
        ->expectsConfirmation('Merge #1 into main (production)?', 'no')
        ->expectsOutputToContain('Not merged.')
        ->assertFailed();

    expect($github->pullRequests[1]['state'])->toBe('OPEN');

    $this->artisan('agentio:release', ['--merge' => true, '--yes' => true])
        ->expectsOutputToContain('Merged #1 into main')
        ->assertSuccessful();

    expect($github->pullRequests[1]['state'])->toBe('MERGED');
});

/**
 * The developer's message $text to the bot (update $update), answered by the assistant with $decision.
 *
 * @param  array<string, mixed>  $decision
 */
function tellTheBot(int $update, string $text, array $decision, ?int $replyTo = null): void
{
    assistantDecides($decision);
    $conversation = app(Conversation::class);

    app(Responder::class)->answer($conversation->putInbox(new IncomingMessage($update, 1000 + $update, '42', $text, replyToId: $replyTo, replyToBot: $replyTo !== null)));
}

it('makes a release the developer asks for in Telegram only after the confirmation', function () {
    $github = repositoryWithOrigin();
    config(['agentio.logs_path' => base_path('storage/logs/agents')]);
    app()->forgetInstance(LoopState::class);

    // A confirmation nobody asked for only opens the pull request and asks.
    tellTheBot(1, 'да, сливай релиз', ['reply' => 'Хорошо.', 'actions' => [['type' => 'release', 'confirm' => true]]]);

    expect($github->pullRequests[1]['state'])->toBe('OPEN')
        ->and($github->callsOf('api'))->toBe([])
        ->and(app(Conversation::class)->pendingRelease())->toBe(['number' => 1, 'head' => $github->head('dev'), 'url' => 'https://github.com/acme/app/pull/1']);
    Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => $job->kind === 'release'
        && str_contains($job->text, 'Подтверждать пока нечего: сначала открою PR релиза.')
        && str_contains($job->text, '🚀 PR релиза #1 dev → main открыт (4 коммитов): https://github.com/acme/app/pull/1')
        && str_contains($job->text, 'Ответьте «да» на это сообщение'));

    tellTheBot(2, 'да', ['reply' => 'Сливаю релиз.', 'actions' => [['type' => 'release', 'confirm' => true]]], replyTo: 900);

    expect($github->pullRequests[1]['state'])->toBe('MERGED')
        ->and($github->subject('main'))->toBe('Merge pull request #1 from acme/dev')
        ->and(app(Conversation::class)->pendingRelease())->toBeNull();
    Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => $job->kind === 'answer'
        && str_contains($job->text, '🚀 Релиз выпущен: PR #1 (dev → main) слит (')
        && str_contains($job->text, 'https://github.com/acme/app/pull/1'));
});

it('asks for the release again when the development branch moved before the confirmation', function () {
    $github = repositoryWithOrigin();
    $project = base_path();
    config(['agentio.logs_path' => $project.'/storage/logs/agents']);
    app()->forgetInstance(LoopState::class);

    tellTheBot(1, 'вмержи dev в main', ['reply' => 'Готовлю релиз.', 'actions' => [['type' => 'release', 'confirm' => false]]]);
    $asked = (string) $github->head('dev');

    git($project, 'checkout', '-q', 'dev');
    file_put_contents($project.'/Late.php', "<?php\n");
    git($project, 'add', '.');
    git($project, 'commit', '-q', '-m', 'Merge pull request from acme/XY-30');
    git($project, 'push', '-q', 'origin', 'dev');

    tellTheBot(2, 'да', ['reply' => 'Сливаю.', 'actions' => [['type' => 'release', 'confirm' => true]]]);

    expect($github->pullRequests[1]['state'])->toBe('OPEN')
        ->and($github->callsOf('api'))->toBe([])
        ->and(app(Conversation::class)->pendingRelease()['head'] ?? null)->toBe($github->head('dev'))->not->toBe($asked);
    Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => $job->kind === 'release'
        && str_contains($job->text, 'подтвердите релиз заново')
        && str_contains($job->text, '🚀 PR релиза #1 dev → main уже открыт (5 коммитов)'));
});
