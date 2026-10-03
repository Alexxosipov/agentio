<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Obrazmisli\Agentio\Agentio;
use Obrazmisli\Agentio\Process\ReadinessGraph;
use Obrazmisli\Agentio\Tests\TestCase;
use Obrazmisli\Agentio\YouTrack\Issue;

uses(TestCase::class)
    ->afterEach(fn () => Agentio::auth(null))
    ->in(__DIR__);

/**
 * An issue in the shape the YouTrack REST API returns it (Client::ISSUE_FIELDS).
 *
 * @param  array<string, string|null>  $fields
 * @param  array<string, list<string>>  $links  Relation (as seen from this issue) => issue ids
 * @param  list<string>  $tags
 * @return array<string, mixed>
 */
function apiIssue(string $id, array $fields = [], array $links = [], array $tags = [], ?string $summary = null): array
{
    $directions = [
        'depends on' => ['INWARD', 'Depend', 'is required for', 'depends on'],
        'is required for' => ['OUTWARD', 'Depend', 'is required for', 'depends on'],
        'subtask of' => ['INWARD', 'Subtask', 'parent for', 'subtask of'],
        'parent for' => ['OUTWARD', 'Subtask', 'parent for', 'subtask of'],
        'relates to' => ['BOTH', 'Relates', 'relates to', ''],
    ];

    return [
        'idReadable' => $id,
        'summary' => $summary ?? 'Summary of '.$id,
        'created' => 1791010361184,
        'updated' => 1791027550675,
        'resolved' => null,
        'customFields' => array_map(
            fn (string $name, ?string $value): array => ['name' => $name, 'value' => $value === null ? null : ['name' => $value]],
            array_keys($fields),
            array_values($fields),
        ),
        'tags' => array_map(fn (string $tag): array => ['id' => 'tag-'.$tag, 'name' => $tag], $tags),
        'links' => array_map(function (string $relation, array $ids) use ($directions): array {
            [$direction, $name, $sourceToTarget, $targetToSource] = $directions[$relation];

            return [
                'direction' => $direction,
                'linkType' => ['name' => $name, 'sourceToTarget' => $sourceToTarget, 'targetToSource' => $targetToSource],
                'issues' => array_map(fn (string $linked): array => ['idReadable' => $linked], $ids),
            ];
        }, array_keys($links), array_values($links)),
    ];
}

/**
 * A readiness graph from compact specs: id => [type, state, parent?, dependsOn?, tags?].
 * "parent for" links are derived from the parents.
 *
 * @param  array<string, array{0: string, 1: string, 2?: string|null, 3?: list<string>, 4?: list<string>}>  $specs
 */
function graphOf(array $specs): ReadinessGraph
{
    $children = [];

    foreach ($specs as $id => $spec) {
        if (($spec[2] ?? null) !== null) {
            $children[$spec[2]][] = $id;
        }
    }

    $issues = [];

    foreach ($specs as $id => $spec) {
        $links = array_filter([
            'subtask of' => ($spec[2] ?? null) === null ? [] : [$spec[2]],
            'parent for' => $children[$id] ?? [],
            'depends on' => $spec[3] ?? [],
        ]);

        $issues[] = Issue::fromApi(apiIssue($id, ['Type' => $spec[0], 'State' => $spec[1]], $links, $spec[4] ?? []));
    }

    return new ReadinessGraph($issues);
}

/**
 * A fresh temporary directory, removed after the test.
 */
function temporaryDirectory(): string
{
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'agentio-'.Str::random(12);
    mkdir($directory, 0777, true);

    test()->beforeApplicationDestroyed(function () use ($directory): void {
        foreach (glob($directory.'/{,.}[!.]*', GLOB_BRACE) ?: [] as $file) {
            unlink($file);
        }

        rmdir($directory);
    });

    return $directory;
}
