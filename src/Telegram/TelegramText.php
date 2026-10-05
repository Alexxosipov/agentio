<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Telegram;

/**
 * Text for Telegram: the Markdown the agents write in YouTrack (**bold**, `code`, # headings, - lists) as the
 * HTML subset of the Bot API, and long texts split into messages.
 */
final class TelegramText
{
    /** Characters of one message: Telegram allows 4096, the rest is room for the HTML entities. */
    public const int CHUNK = 3500;

    /**
     * Markdown as Telegram HTML: <b>, <i>, <code>, <pre>; everything else is escaped.
     */
    public static function html(string $markdown): string
    {
        $parts = preg_split('/(```[a-z]*\n?.*?```)/s', $markdown, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $html = '';

        foreach ($parts as $part) {
            if (str_starts_with($part, '```')) {
                $code = (string) preg_replace('/^```[a-z]*\n?|```$/', '', $part);
                $html .= '<pre>'.self::escape(rtrim($code, "\n")).'</pre>';

                continue;
            }

            $text = self::escape($part);
            $text = (string) preg_replace('/^#{1,6}\s+(.+)$/m', '<b>$1</b>', $text);
            $text = (string) preg_replace('/`([^`\n]+)`/', '<code>$1</code>', $text);
            $text = (string) preg_replace('/\*\*(.+?)\*\*/s', '<b>$1</b>', $text);
            $text = (string) preg_replace('/(?<![\w*])_(?!\s)([^_\n]+?)(?<!\s)_(?![\w*])/u', '<i>$1</i>', $text);
            $html .= $text;
        }

        return $html;
    }

    /**
     * The text without the Markdown markers (the plain fallback when Telegram rejects the HTML).
     */
    public static function plain(string $markdown): string
    {
        $text = (string) preg_replace('/^```[a-z]*\n?|^```$/m', '', $markdown);
        $text = (string) preg_replace('/^#{1,6}\s+/m', '', $text);

        return str_replace(['**', '`'], '', $text);
    }

    /**
     * The text split into messages: at paragraph boundaries, else at line ends, else hard.
     *
     * @return list<string>
     */
    public static function chunks(string $text, int $limit = self::CHUNK): array
    {
        $text = trim(str_replace("\r\n", "\n", $text));

        if ($text === '') {
            return [];
        }

        $chunks = [];
        $current = '';

        foreach (preg_split('/(?<=\n\n)/', $text) ?: [] as $paragraph) {
            foreach (self::pieces($paragraph, $limit) as $piece) {
                if ($current !== '' && mb_strlen($current.$piece) > $limit) {
                    $chunks[] = trim($current);
                    $current = '';
                }

                $current .= $piece;
            }
        }

        if (trim($current) !== '') {
            $chunks[] = trim($current);
        }

        return $chunks;
    }

    /**
     * The text cut to the length, with an ellipsis when it was longer.
     */
    public static function limit(string $text, int $length): string
    {
        $text = trim($text);

        return mb_strlen($text) <= $length ? $text : rtrim(mb_substr($text, 0, $length - 1)).'…';
    }

    public static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * A paragraph in pieces no longer than the limit: whole lines, a line longer than the limit cut.
     *
     * @return list<string>
     */
    private static function pieces(string $paragraph, int $limit): array
    {
        if (mb_strlen($paragraph) <= $limit) {
            return [$paragraph];
        }

        $pieces = [];

        foreach (preg_split('/(?<=\n)/', $paragraph) ?: [] as $line) {
            while (mb_strlen($line) > $limit) {
                $pieces[] = mb_substr($line, 0, $limit);
                $line = mb_substr($line, $limit);
            }

            $pieces[] = $line;
        }

        return $pieces;
    }
}
