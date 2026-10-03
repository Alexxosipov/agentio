<?php

declare(strict_types=1);

use Obrazmisli\Agentio\Runtime\ReverseLineReader;

/**
 * @return array<int, string>
 */
function reversed(string $contents, int $maxBytes = PHP_INT_MAX): array
{
    $path = temporaryDirectory().'/file.log';
    file_put_contents($path, $contents);

    return iterator_to_array(ReverseLineReader::lines($path, $maxBytes));
}

it('yields lines from the end with their offsets', function () {
    expect(reversed("one\ntwo\r\nthree\n"))->toBe([9 => 'three', 4 => 'two', 0 => 'one']);
});

it('includes a last line without a newline', function () {
    expect(reversed("one\ntwo"))->toBe([4 => 'two', 0 => 'one']);
});

it('keeps empty lines inside the file', function () {
    expect(reversed("one\n\nthree\n"))->toBe([5 => 'three', 4 => '', 0 => 'one']);
});

it('reads lines longer than a chunk', function () {
    $long = str_repeat('x', 200_000);

    expect(reversed("first\n{$long}\nlast\n"))->toBe([200_007 => 'last', 6 => $long, 0 => 'first']);
});

it('stops after the byte limit without yielding a partial line', function () {
    expect(reversed("first line\nsecond\nthird\n", 14))->toBe([18 => 'third', 11 => 'second']);
});

it('yields nothing for empty or missing files', function () {
    expect(reversed(''))->toBe([])
        ->and(iterator_to_array(ReverseLineReader::lines('/nonexistent/agentio.log')))->toBe([]);
});
