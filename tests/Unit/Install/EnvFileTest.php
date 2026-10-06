<?php

declare(strict_types=1);

use Obrazmisli\Agentio\Install\EnvFile;

it('reads values the way Laravel does', function () {
    $file = temporaryDirectory().'/.env';
    file_put_contents($file, "A=plain # comment\nexport B=\"quoted # not a comment\"\nC='single'\nEMPTY=\n#D=commented\n");
    $env = new EnvFile($file);

    expect($env->get('A'))->toBe('plain')
        ->and($env->get('B'))->toBe('quoted # not a comment')
        ->and($env->get('C'))->toBe('single')
        ->and($env->get('EMPTY'))->toBeNull()
        ->and($env->get('D'))->toBeNull()
        ->and((new EnvFile($file.'.missing'))->get('A'))->toBeNull();
});

it('sets keys in place and appends the new ones', function () {
    $file = temporaryDirectory().'/.env';
    file_put_contents($file, "A=1\n  export B=2\nC=3\n");

    expect((new EnvFile($file))->contentWith(['B' => 'two', 'C' => '3', 'D' => null, 'E' => 'with space']))
        ->toBe("A=1\nB=two\nC=3\n\n# agentio\nE=\"with space\"\n")
        ->and((new EnvFile($file.'.missing'))->contentWith(['A' => 'x']))->toBe("# agentio\nA=x\n");
});

it('sets a commented-out key on its line', function () {
    $file = temporaryDirectory().'/.env';
    file_put_contents($file, "DB_CONNECTION=sqlite\n# DB_HOST=127.0.0.1\n#DB_PORT=3306\n");
    $env = new EnvFile($file);

    expect($env->contentWith(['DB_HOST' => 'localhost', 'DB_PORT' => '5432', 'DB_DATABASE' => 'app']))
        ->toBe("DB_CONNECTION=sqlite\nDB_HOST=localhost\nDB_PORT=5432\n\n# agentio\nDB_DATABASE=app\n")
        ->and($env->has('DB_CONNECTION'))->toBeTrue()
        ->and($env->has('DB_HOST'))->toBeFalse();
});

it('quotes only values that need it', function (string $value, string $written) {
    expect(EnvFile::quote($value))->toBe($written);
})->with([
    'url' => ['https://yt.example.com/youtrack', 'https://yt.example.com/youtrack'],
    'token' => ['perm:YS5i.NDctMQ==.Zm9v', 'perm:YS5i.NDctMQ==.Zm9v'],
    'space' => ['a b', '"a b"'],
    'quote and backslash' => ['a"b\\c', '"a\\"b\\\\c"'],
]);

it('writes a new .env readable by its owner only and keeps the mode of an existing one', function () {
    $directory = temporaryDirectory();
    $env = new EnvFile($directory.'/.env');

    expect($env->put(['AGENTIO_TELEGRAM_BOT_TOKEN' => '1:abc']))->toBeTrue()
        ->and(fileperms($directory.'/.env') & 0777)->toBe(0600)
        ->and($env->put(['AGENTIO_TELEGRAM_BOT_TOKEN' => '1:abc']))->toBeFalse();

    chmod($directory.'/.env', 0640);
    $env->put(['APP_NAME' => 'x']);

    expect(fileperms($directory.'/.env') & 0777)->toBe(0640)
        ->and(glob($directory.'/.env.agentio-*'))->toBe([]);
});
