<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Obrazmisli\Agentio\Agentio;
use Obrazmisli\Agentio\Http\Controllers\AssetController;
use Obrazmisli\Agentio\YouTrack\IssueRepository;

beforeEach(function () {
    app()->detectEnvironment(fn (): string => 'local');
});

it('renders the dashboard shell with its assets and endpoints', function () {
    config(['agentio.ui.poll' => 7, 'agentio.youtrack.url' => 'https://yt.example.com/', 'agentio.youtrack.token' => 'secret']);

    $css = AssetController::version('app.css');
    $js = AssetController::version('app.js');

    $this->get('/agentio')
        ->assertOk()
        ->assertSee('<html lang="ru">', false)
        ->assertSee('Agentio · TP')
        ->assertSee('href="http://localhost/agentio/assets/app.css?v='.$css.'"', false)
        ->assertSee('src="http://localhost/agentio/assets/app.js?v='.$js.'"', false)
        ->assertSee('"poll":7', false)
        ->assertSee('"youtrackUrl":"https://yt.example.com"', false)
        ->assertSee('"epic":"http://localhost/agentio/api/epics/__ID__"', false)
        ->assertSee('"loopLog":"http://localhost/agentio/api/loop-log"', false)
        ->assertDontSee('secret');

    expect($css)->toMatch('/^[0-9a-f]{12}$/')->and($js)->not->toBe($css);
});

it('embeds the configuration safely', function () {
    config(['agentio.youtrack.project' => 'X</script><script>alert(1)</script>']);
    app()->forgetInstance(IssueRepository::class);

    $this->get('/agentio')
        ->assertOk()
        ->assertDontSee('</script><script>alert(1)', false)
        ->assertSee('"project":"X\\u003C/script\\u003E', false);
});

it('serves the stylesheet and the script with their content types', function () {
    $this->get('/agentio/assets/app.css')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/css; charset=UTF-8')
        ->assertHeader('Cache-Control', 'no-cache, private')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertSee('--accent', false);

    $this->get('/agentio/assets/app.js?v='.AssetController::version('app.js'))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/javascript; charset=UTF-8')
        ->assertHeader('Cache-Control', 'immutable, max-age=31536000, public')
        ->assertHeader('ETag', '"'.AssetController::version('app.js').'"');

    $this->get('/agentio/assets/other.js')->assertNotFound();
    $this->get('/agentio/assets/..%2Fviews%2Findex.blade.php')->assertNotFound();
    expect(AssetController::version('missing.css'))->toBe('');
});

it('protects the assets like the page', function () {
    app()->detectEnvironment(fn (): string => 'production');

    $this->get('/agentio/assets/app.js')->assertForbidden();

    Agentio::auth(fn (Request $request): bool => true);

    $this->get('/agentio/assets/app.js')->assertOk();
});
