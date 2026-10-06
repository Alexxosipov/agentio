<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Install;

/**
 * The local services of the host project in docker compose (Installer::SERVICES): PostgreSQL with the development
 * database and the database of the tests, Redis for the queues and Horizon. compose.yaml takes the credentials
 * from .env; phpunit.xml points the tests at the testing database.
 *
 * A project on SQLite (the default of a new Laravel application) is switched to PostgreSQL; a project already on
 * PostgreSQL keeps its values and gets only the missing ones; a project on another database (MySQL, MariaDB,
 * SQL Server) is left alone: the services are not installed.
 */
final readonly class LocalServices
{
    /** The database of the tests, created by the initdb script of the compose.yaml stub. */
    public const string TEST_DATABASE = 'testing';

    /** The .env values for the services of compose.yaml. */
    public const array ENVIRONMENT = [
        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => '127.0.0.1',
        'DB_PORT' => '5432',
        'DB_DATABASE' => 'laravel',
        'DB_USERNAME' => 'laravel',
        'DB_PASSWORD' => 'password',
        'REDIS_HOST' => '127.0.0.1',
        'REDIS_PORT' => '6379',
    ];

    /** The phpunit.xml values: the tests run against the testing database (DB_URL would override it). */
    public const array PHPUNIT = [
        'DB_CONNECTION' => 'pgsql',
        'DB_DATABASE' => self::TEST_DATABASE,
        'DB_URL' => '',
    ];

    /** The database connections the services replace. */
    private const array SUPPORTED_CONNECTIONS = ['sqlite', 'pgsql'];

    public function __construct(private string $basePath) {}

    /**
     * The database connection of the project (.env, else .env.example; Laravel's default is sqlite).
     */
    public function connection(): string
    {
        foreach (['.env', '.env.example'] as $file) {
            $connection = (new EnvFile($this->path($file)))->get('DB_CONNECTION');

            if ($connection !== null) {
                return $connection;
            }
        }

        return 'sqlite';
    }

    /**
     * Whether the services fit the project: it uses SQLite or PostgreSQL.
     */
    public function supported(): bool
    {
        return in_array($this->connection(), self::SUPPORTED_CONNECTIONS, true);
    }

    /**
     * The values to set in a dotenv file (.env or .env.example) of the project: every one on SQLite, the keys
     * the file does not define on PostgreSQL, none on another database.
     *
     * @return array<string, string>
     */
    public function environment(string $file = '.env'): array
    {
        $env = new EnvFile($this->path($file));
        $connection = $env->get('DB_CONNECTION') ?? 'sqlite';

        if (! in_array($connection, self::SUPPORTED_CONNECTIONS, true)) {
            return [];
        }

        return array_filter(
            self::ENVIRONMENT,
            fn (string $value, string $key): bool => ($connection === 'sqlite' && str_starts_with($key, 'DB_')) || ! $env->has($key),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * Point the tests at the testing database: the <env> of phpunit.xml (or phpunit.xml.dist) set, a commented
     * one uncommented, a missing one added. Null when the project has no PHPUnit configuration.
     */
    public function configurePhpUnit(bool $dryRun = false): ?FileChange
    {
        foreach (['phpunit.xml', 'phpunit.xml.dist'] as $file) {
            if (! is_file($this->path($file))) {
                continue;
            }

            $content = (string) file_get_contents($this->path($file));
            $updated = self::phpUnitWith($content, self::PHPUNIT);

            if ($updated === $content) {
                return new FileChange($file, FileStatus::Unchanged);
            }

            if (! $dryRun) {
                file_put_contents($this->path($file), $updated);
            }

            return new FileChange($file, FileStatus::Updated, 'tests on the PostgreSQL database '.self::TEST_DATABASE);
        }

        return null;
    }

    /**
     * The PHPUnit configuration with the environment variables set, keeping its formatting: an <env> is replaced,
     * a commented-out one (<!-- <env …/> -->) is uncommented, a missing one is added to <php>.
     *
     * @param  array<string, string>  $values
     */
    public static function phpUnitWith(string $content, array $values): string
    {
        foreach ($values as $name => $value) {
            $element = '<env name="'.$name.'" value="'.htmlspecialchars($value, ENT_XML1 | ENT_QUOTES).'"/>';
            $env = '<env\s+name="'.preg_quote($name, '/').'"[^>]*?\/>';

            $content = self::replaceOutsideComments('/'.$env.'/', $element, $content)
                ?? self::replaceOutsideComments('/<!--\s*'.$env.'\s*-->/', $element, $content, comments: true)
                ?? self::addEnv($content, $element);
        }

        return $content;
    }

    /**
     * The content with the first match replaced, searched outside the XML comments (or in them only); null when
     * nothing matches.
     */
    private static function replaceOutsideComments(string $pattern, string $replacement, string $content, bool $comments = false): ?string
    {
        $parts = preg_split('/(<!--.*?-->)/s', $content, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$content];

        foreach ($parts as $index => $part) {
            if (str_starts_with($part, '<!--') !== $comments) {
                continue;
            }

            $replaced = (string) preg_replace_callback($pattern, fn (): string => $replacement, $part, 1, $count);

            if ($count > 0) {
                $parts[$index] = $replaced;

                return implode('', $parts);
            }
        }

        return null;
    }

    /**
     * The content with the <env> added at the end of <php> (a <php> added when there is none).
     */
    private static function addEnv(string $content, string $element): string
    {
        if (preg_match('/^([ \t]*)<\/php>/m', $content, $closing) === 1) {
            $indent = preg_match('/^([ \t]*)<env\s/m', $content, $sibling) === 1 ? $sibling[1] : $closing[1].'    ';

            return (string) preg_replace('/^[ \t]*<\/php>/m', $indent.$element."\n".$closing[1].'</php>', $content, 1);
        }

        return (string) preg_replace('/^[ \t]*<\/phpunit>/m', "    <php>\n        {$element}\n    </php>\n</phpunit>", $content, 1);
    }

    private function path(string $file): string
    {
        return rtrim($this->basePath, '/').'/'.$file;
    }
}
