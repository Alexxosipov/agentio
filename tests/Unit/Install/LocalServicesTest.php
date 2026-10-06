<?php

declare(strict_types=1);

use Obrazmisli\Agentio\Install\FileStatus;
use Obrazmisli\Agentio\Install\LocalServices;

it('uncomments the database variables of an older phpunit.xml', function () {
    $content = "<phpunit>\n    <php>\n        <env name=\"APP_ENV\" value=\"testing\"/>\n        <!-- <env name=\"DB_CONNECTION\" value=\"sqlite\"/> -->\n        <!-- <env name=\"DB_DATABASE\" value=\":memory:\"/> -->\n    </php>\n</phpunit>\n";

    expect(LocalServices::phpUnitWith($content, LocalServices::PHPUNIT))
        ->toBe("<phpunit>\n    <php>\n        <env name=\"APP_ENV\" value=\"testing\"/>\n        <env name=\"DB_CONNECTION\" value=\"pgsql\"/>\n        <env name=\"DB_DATABASE\" value=\"testing\"/>\n        <env name=\"DB_URL\" value=\"\"/>\n    </php>\n</phpunit>\n");
});

it('replaces the active variable rather than a commented one and adds <php> when there is none', function () {
    expect(LocalServices::phpUnitWith("<php>\n    <!-- <env name=\"DB_CONNECTION\" value=\"mysql\"/> -->\n    <env name=\"DB_CONNECTION\" value=\"sqlite\" force=\"true\"/>\n</php>\n", ['DB_CONNECTION' => 'pgsql']))
        ->toBe("<php>\n    <!-- <env name=\"DB_CONNECTION\" value=\"mysql\"/> -->\n    <env name=\"DB_CONNECTION\" value=\"pgsql\"/>\n</php>\n")
        ->and(LocalServices::phpUnitWith("<phpunit>\n</phpunit>\n", ['DB_DATABASE' => 'testing']))
        ->toBe("<phpunit>\n    <php>\n        <env name=\"DB_DATABASE\" value=\"testing\"/>\n    </php>\n</phpunit>\n");
});

it('changes phpunit.xml, else phpunit.xml.dist, only when it differs', function () {
    $project = temporaryDirectory();
    $services = new LocalServices($project);

    expect($services->configurePhpUnit())->toBeNull();

    file_put_contents($project.'/phpunit.xml.dist', "<phpunit>\n</phpunit>\n");

    expect($services->configurePhpUnit(dryRun: true)?->status)->toBe(FileStatus::Updated)
        ->and(file_get_contents($project.'/phpunit.xml.dist'))->toBe("<phpunit>\n</phpunit>\n")
        ->and($services->configurePhpUnit()?->path)->toBe('phpunit.xml.dist')
        ->and($services->configurePhpUnit()?->status)->toBe(FileStatus::Unchanged);
});

it('switches SQLite to PostgreSQL, fills in PostgreSQL and leaves other databases alone', function (string $env, array $expected, bool $supported) {
    $project = temporaryDirectory();
    file_put_contents($project.'/.env', $env);
    $services = new LocalServices($project);

    expect($services->environment())->toBe($expected)
        ->and($services->supported())->toBe($supported);
})->with([
    'no .env values' => ['', LocalServices::ENVIRONMENT, true],
    'sqlite' => ["DB_CONNECTION=sqlite\nDB_DATABASE=/app/database/database.sqlite\nREDIS_HOST=redis\n", array_diff_key(LocalServices::ENVIRONMENT, ['REDIS_HOST' => true]), true],
    'pgsql' => ["DB_CONNECTION=pgsql\nDB_HOST=db\nDB_PORT=5433\nDB_DATABASE=app\nDB_USERNAME=app\nDB_PASSWORD=\n", ['REDIS_HOST' => '127.0.0.1', 'REDIS_PORT' => '6379'], true],
    'mysql' => ["DB_CONNECTION=mysql\n", [], false],
]);
