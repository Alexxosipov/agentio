<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Serves the dashboard's own stylesheet and script, so the host application needs no frontend build and no CDN.
 * A request carrying the current content hash (?v=...) is cacheable forever.
 */
final class AssetController
{
    private const array TYPES = [
        'css' => 'text/css; charset=UTF-8',
        'js' => 'text/javascript; charset=UTF-8',
    ];

    public function __invoke(Request $request, string $file): Response
    {
        $path = self::path($file);

        abort_if($path === null, 404);

        $version = self::version($file);

        return new Response((string) file_get_contents($path), 200, [
            'Content-Type' => self::TYPES[pathinfo($file, PATHINFO_EXTENSION)],
            'Cache-Control' => $request->query('v') === $version ? 'public, max-age=31536000, immutable' : 'no-cache',
            'ETag' => '"'.$version.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * The URL of an asset with its content hash for cache busting.
     */
    public static function url(string $file): string
    {
        return route('agentio.asset', ['file' => $file, 'v' => self::version($file)]);
    }

    /**
     * The first 12 characters of the asset's content hash.
     */
    public static function version(string $file): string
    {
        $path = self::path($file);

        return $path === null ? '' : substr((string) hash_file('xxh128', $path), 0, 12);
    }

    private static function path(string $file): ?string
    {
        $path = __DIR__.'/../../../resources/assets/'.basename($file);

        return array_key_exists(pathinfo($file, PATHINFO_EXTENSION), self::TYPES) && is_file($path) ? $path : null;
    }
}
