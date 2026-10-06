<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Install;

/**
 * A dotenv file of the host project (.env): reads values the way Laravel does and sets keys in place,
 * keeping every other line. A key that is already there is replaced on its line, a commented-out key
 * ("# DB_HOST=127.0.0.1" of a fresh Laravel project) is replaced on its line too, a new key is appended.
 */
final readonly class EnvFile
{
    public function __construct(private string $path) {}

    public function exists(): bool
    {
        return is_file($this->path);
    }

    /**
     * The value of a key (quotes removed, an inline comment of an unquoted value dropped), or null when the
     * key is missing or empty.
     */
    public function get(string $key): ?string
    {
        foreach (explode("\n", $this->content()) as $line) {
            if (preg_match('/^\s*(?:export\s+)?'.preg_quote($key, '/').'\s*=\s*(.*)$/', $line, $match) !== 1) {
                continue;
            }

            $raw = trim($match[1]);
            $value = preg_match('/^(["\'])(.*)\1$/', $raw, $quoted) === 1
                ? $quoted[2]
                : trim((string) preg_replace('/\s+#.*$/', '', $raw));

            return $value === '' ? null : $value;
        }

        return null;
    }

    /**
     * The file content with the keys set; keys with a null value or with the same value already are left as
     * they are.
     *
     * @param  array<string, string|null>  $values
     */
    public function contentWith(array $values): string
    {
        $content = $this->content();
        $appended = [];

        foreach ($values as $key => $value) {
            if ($value === null || $this->get($key) === $value) {
                continue;
            }

            $line = $key.'='.self::quote($value);
            $pattern = '/^[ \t]*(?:export[ \t]+)?'.preg_quote($key, '/').'[ \t]*=.*$/m';
            $commented = '/^[ \t]*#[ \t]*(?:export[ \t]+)?'.preg_quote($key, '/').'[ \t]*=.*$/m';

            if (preg_match($pattern, $content) === 1) {
                $content = (string) preg_replace_callback($pattern, fn (): string => $line, $content, 1);
            } elseif (preg_match($commented, $content) === 1) {
                $content = (string) preg_replace_callback($commented, fn (): string => $line, $content, 1);
            } else {
                $appended[] = $line;
            }
        }

        if ($appended === []) {
            return $content;
        }

        $prefix = $content === '' ? '' : rtrim($content, "\n")."\n\n";

        return $prefix."# agentio\n".implode("\n", $appended)."\n";
    }

    /**
     * Set the keys in the file (see contentWith()); returns whether the file changed.
     *
     * @param  array<string, string|null>  $values
     */
    public function put(array $values): bool
    {
        $content = $this->contentWith($values);

        if ($this->exists() && $content === $this->content()) {
            return false;
        }

        // Written next to it and renamed: a reader never sees half a file (a symlinked .env keeps its link).
        $target = is_link($this->path) ? (realpath($this->path) ?: $this->path) : $this->path;
        $temporary = $target.'.agentio-'.bin2hex(random_bytes(4));
        file_put_contents($temporary, $content);

        // A new .env holds secrets: readable by its owner only.
        chmod($temporary, is_file($target) ? (fileperms($target) & 0777) : 0600);

        rename($temporary, $target);

        return true;
    }

    /**
     * The environment of a child process that reads the file afresh: every key of the file unset (false), so
     * the values the current process loaded from it are not inherited.
     *
     * @return array<string, false>
     */
    public function unloaded(): array
    {
        return array_fill_keys($this->keys(), false);
    }

    /**
     * Whether the file defines the key (with any value, an empty one too).
     */
    public function has(string $key): bool
    {
        return in_array($key, $this->keys(), true);
    }

    /**
     * The keys the file defines, in order.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        preg_match_all('/^[ \t]*(?:export[ \t]+)?([A-Za-z_][A-Za-z0-9_.]*)[ \t]*=/m', $this->content(), $matches);

        return array_values(array_unique($matches[1]));
    }

    public function content(): string
    {
        return $this->exists() ? (string) file_get_contents($this->path) : '';
    }

    /**
     * A value as it is written to the file: bare when it is safe (URLs, tokens), otherwise in double quotes.
     */
    public static function quote(string $value): string
    {
        if (preg_match('#^[A-Za-z0-9_./:=+@,-]*$#', $value) === 1) {
            return $value;
        }

        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }
}
