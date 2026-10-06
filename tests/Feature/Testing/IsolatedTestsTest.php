<?php

declare(strict_types=1);

use Illuminate\Testing\ParallelTesting;
use Obrazmisli\Agentio\Testing\IsolatedTests;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

// pest --parallel runs this suite as parallel-testing processes of Laravel itself: their flag is kept.
beforeEach(fn () => $this->parallel = $_SERVER['LARAVEL_PARALLEL_TESTING'] ?? null);

afterEach(function (): void {
    IsolatedTests::release();
    unset($_SERVER[IsolatedTests::ENV]);

    if ($this->parallel === null) {
        unset($_SERVER['LARAVEL_PARALLEL_TESTING']);
    } else {
        $_SERVER['LARAVEL_PARALLEL_TESTING'] = $this->parallel;
    }
});

it('leaves the tests alone outside the sessions of the loop', function () {
    IsolatedTests::register(app());

    expect($_SERVER['LARAVEL_PARALLEL_TESTING'] ?? null)->toBe($this->parallel)
        ->and((string) app(ParallelTesting::class)->token())->not->toStartWith('agentio_');
});

it('runs the tests of an agent as a parallel-testing process with the token of its slot', function () {
    $_SERVER[IsolatedTests::ENV] = '1';

    IsolatedTests::register(app());

    expect($_SERVER['LARAVEL_PARALLEL_TESTING'])->toBe(1)
        ->and(app(ParallelTesting::class)->token())->toMatch('/^agentio_\d+$/')
        ->and(app(ParallelTesting::class)->token())->toBe(IsolatedTests::token());
});

it('gives the processes that run at once different slots and frees a slot when its process exits', function () {
    $directory = temporaryDirectory();
    $autoload = var_export(dirname(__DIR__, 3).'/vendor/autoload.php', true);
    $lockDirectory = var_export($directory, true);
    file_put_contents($directory.'/hold-slot.php', <<<PHP
        <?php
        require {$autoload};
        echo Obrazmisli\Agentio\Testing\IsolatedTests::token({$lockDirectory}), "\\n";
        fgets(STDIN);
        PHP);
    $other = new Process([PHP_BINARY, $directory.'/hold-slot.php']);
    $other->setInput($input = new InputStream);
    $other->start();
    $other->waitUntil(fn (string $type, string $output): bool => str_contains($output, "\n"));

    expect(trim($other->getOutput()))->toBe('agentio_1')
        ->and(IsolatedTests::token($directory))->toBe('agentio_2');

    $input->close();
    $other->wait();
    IsolatedTests::release();

    expect(IsolatedTests::token($directory))->toBe('agentio_1');
});
