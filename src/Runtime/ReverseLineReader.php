<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Runtime;

use Generator;

/**
 * Reads the lines of a file from the end without loading the whole file.
 *
 * @internal
 */
final class ReverseLineReader
{
    private const int CHUNK = 65536;

    /**
     * Lines from the last to the first, keyed by the byte offset of the line start.
     * A trailing line without a newline is included. Stops after $maxBytes have been read.
     *
     * @return Generator<int, string>
     */
    public static function lines(string $path, int $maxBytes = PHP_INT_MAX): Generator
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return;
        }

        try {
            $position = (int) filesize($path);
            $stop = max(0, $position - $maxBytes);
            $buffer = '';
            $atEnd = true;

            while ($position > $stop) {
                $length = max(1, min(self::CHUNK, $position - $stop));
                $position -= $length;
                fseek($handle, $position);
                $buffer = fread($handle, $length).$buffer;

                while (($newline = strrpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, $newline + 1);
                    $buffer = substr($buffer, 0, $newline);

                    if (! $atEnd || $line !== '') {
                        yield $position + $newline + 1 => rtrim($line, "\r");
                    }

                    $atEnd = false;
                }
            }

            if ($position === 0 && $buffer !== '') {
                yield 0 => rtrim($buffer, "\r");
            }
        } finally {
            fclose($handle);
        }
    }
}
