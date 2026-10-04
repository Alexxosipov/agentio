<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Process;

/**
 * The slug of an epic branch `epic/<ID>-<slug>`: the summary without its [EPIC] prefix, transliterated to
 * lowercase ASCII words joined with dashes, at most 40 characters.
 */
final class BranchSlug
{
    public static function of(string $summary): string
    {
        $text = (string) preg_replace('/^\s*\[[A-Z]+\]\s*/', '', $summary);
        $latin = function_exists('transliterator_transliterate')
            ? transliterator_transliterate('Any-Latin; Latin-ASCII', $text)
            : iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        $text = mb_strtolower(str_replace(["'", '"', 'ʹ', 'ʺ'], '', is_string($latin) && $latin !== '' ? $latin : $text));
        $text = trim((string) preg_replace('/[^a-z0-9]+/', '-', $text), '-');

        return rtrim(mb_substr($text, 0, 40), '-') ?: 'epic';
    }
}
