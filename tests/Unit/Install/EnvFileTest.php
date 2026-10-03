<?php

declare(strict_types=1);

use Obrazmisli\Agentio\Install\EnvFile;

it('reads values the way scripts/yt.php does', function () {
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

it('quotes only values that need it', function (string $value, string $written) {
    expect(EnvFile::quote($value))->toBe($written);
})->with([
    'url' => ['https://yt.example.com/youtrack', 'https://yt.example.com/youtrack'],
    'token' => ['perm:YS5i.NDctMQ==.Zm9v', 'perm:YS5i.NDctMQ==.Zm9v'],
    'space' => ['a b', '"a b"'],
    'quote and backslash' => ['a"b\\c', '"a\\"b\\\\c"'],
]);
