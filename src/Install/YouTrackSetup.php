<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Install;

use Obrazmisli\Agentio\YouTrack\Client;
use Obrazmisli\Agentio\YouTrack\IssueType;
use Obrazmisli\Agentio\YouTrack\State;
use Obrazmisli\Agentio\YouTrack\Tag;
use Obrazmisli\Agentio\YouTrack\YouTrackException;

/**
 * Configures a YouTrack project for the autonomous cycle, idempotently: the State, Type and Stage fields
 * with their bundles, the idea / agent-claimed tags, the "<KEY>: …" saved searches and the knowledge base
 * tree. Everything is looked up by name first (an article preferably under its expected parent); existing
 * entities are never deleted, renamed or moved, an existing bundle only gets the missing values and an
 * existing article is never changed, so the articles of an older tree stay where they are. A bundle the
 * project shares with other projects, or the default bundle new projects get, is never changed: an empty
 * project is switched to a bundle of its own ("<KEY> States"), a project with issues gets a warning.
 */
final class YouTrackSetup
{
    /** Stage values (the Kanban board columns); Stage is derived from State. */
    public const array STAGES = ['Backlog', 'Develop', 'Review', 'Test', 'Staging', 'Done'];

    /** @var list<SetupAction> */
    private array $actions = [];

    /** @var array<string, list<array<array-key, mixed>>> */
    private array $bundles = [];

    /** @var list<array<array-key, mixed>>|null */
    private ?array $globalFields = null;

    public function __construct(
        private readonly Client $client,
        private readonly KnowledgeBase $knowledgeBase,
        private readonly bool $dryRun = false,
    ) {}

    /**
     * Saved searches (name suffix => query), as in the youtrack-workflow skill.
     *
     * @return array<string, string>
     */
    public static function savedSearches(string $project): array
    {
        $claimed = Tag::Claimed->value;

        return [
            $project.': идеи без плана' => "project: {$project} tag: idea State: Backlog tag: -{{$claimed}}",
            $project.': готовые эпики' => "project: {$project} Type: Epic State: Ready tag: -{{$claimed}}",
            $project.': готовые задачи' => "project: {$project} Type: Task State: Ready tag: -{{$claimed}}",
            $project.': заблокированные (причина в [AGENT:BLOCKED])' => "project: {$project} State: Blocked",
            $project.': в работе у агентов' => "project: {$project} tag: {{$claimed}}",
            $project.': эпики на приёмке' => "project: {$project} Type: Epic State: Review",
        ];
    }

    /**
     * Run (or, in a dry run, plan) the setup. Returns the knowledge base ids found or created; in a dry run
     * articles that would be created have no id.
     *
     * @return array<string, string> Knowledge base key => article id (e.g. "overview" => "TP-A-1")
     *
     * @throws YouTrackException
     */
    public function run(string $project, Placeholders $placeholders): array
    {
        $this->actions = [];
        $projectId = $this->client->projectId($project);
        $fields = [];

        foreach ($this->client->projectCustomFields($projectId) as $field) {
            $name = $field['field']['name'] ?? null;

            if (is_string($name)) {
                $fields[$name] = $field;
            }
        }

        $states = array_map(fn (State $state): array => ['name' => $state->value, 'isResolved' => $state === State::Done], State::cases());
        $types = array_map(fn (IssueType $type): array => ['name' => $type->value], IssueType::cases());
        $stages = array_map(fn (string $stage): array => ['name' => $stage, 'isResolved' => $stage === 'Done'], self::STAGES);

        $this->ensureField($project, $projectId, $fields, 'State', 'state', $project.' States', $states);
        $this->ensureField($project, $projectId, $fields, 'Type', 'enum', $project.' Types', $types);
        $this->ensureField($project, $projectId, $fields, 'Stage', 'state', $project.' Stages', $stages);

        $this->ensureTags([Tag::Idea->value, Tag::Claimed->value]);
        $this->ensureSavedSearches(self::savedSearches($project));

        return $this->ensureArticles($project, $projectId, $placeholders);
    }

    /**
     * @return list<SetupAction>
     */
    public function actions(): array
    {
        return $this->actions;
    }

    /**
     * @param  array<string, array<array-key, mixed>>  $projectFields  Field name => project custom field
     * @param  'state'|'enum'  $kind
     * @param  list<array<string, mixed>>  $values
     *
     * @throws YouTrackException
     */
    private function ensureField(string $project, string $projectId, array $projectFields, string $name, string $kind, string $bundleName, array $values): void
    {
        $bundleType = $kind === 'state' ? 'StateBundle' : 'EnumBundle';
        $attached = $projectFields[$name] ?? null;

        if ($attached !== null) {
            $bundle = is_array($attached['bundle'] ?? null) ? $attached['bundle'] : [];

            if (($bundle['$type'] ?? $bundleType) !== $bundleType || ! is_string($bundle['id'] ?? null)) {
                $this->record('field', $name, SetupStatus::Warning, sprintf('attached with a %s bundle, expected %s; left as is', (string) ($bundle['$type'] ?? 'missing'), $bundleType));

                return;
            }

            $current = (string) ($bundle['name'] ?? $bundle['id']);

            if (! $this->isShared($name, $projectId, $bundle['id'])) {
                $this->record('field', $name, SetupStatus::Exists, 'bundle '.$current);
                $this->ensureBundleValues($kind, $this->findBundle($kind, 'id', $bundle['id']) ?? $bundle, $values);

                return;
            }

            if (! is_string($attached['id'] ?? null) || $this->client->projectHasIssues($project)) {
                $this->record('field', $name, SetupStatus::Warning, "bundle {$current} is shared with other projects (or is the default of new projects), so it is not changed: give the project a bundle of its own with the values ".implode(', ', array_column($values, 'name')));

                return;
            }

            $own = $this->ensureBundle($kind, $bundleName, $values);
            $this->record('field', $name, SetupStatus::Update, "switch from the shared bundle {$current} to {$bundleName}");

            if (! $this->dryRun && is_string($own['id'] ?? null)) {
                $this->client->setProjectFieldBundle($projectId, $attached['id'], (string) ($attached['$type'] ?? ucfirst($kind).'ProjectCustomField'), $own['id'], $bundleType);
            }

            return;
        }

        $bundle = $this->ensureBundle($kind, $bundleName, $values);

        $field = $this->globalField($name);
        $fieldType = $kind.'[1]';

        if ($field !== null && ($field['fieldType']['id'] ?? null) !== $fieldType) {
            $this->record('field', $name, SetupStatus::Warning, sprintf('a global field of type %s exists, expected %s; not attached', (string) ($field['fieldType']['id'] ?? '?'), $fieldType));

            return;
        }

        if ($field === null) {
            $this->record('field', $name.' (global)', SetupStatus::Create, $fieldType);
            $field = $this->dryRun ? [] : $this->client->createCustomField($name, $fieldType);
        }

        $this->record('field', $name, SetupStatus::Create, 'attach to the project with bundle '.$bundleName);

        if (! $this->dryRun && is_string($field['id'] ?? null) && is_string($bundle['id'] ?? null)) {
            $this->client->attachCustomField($projectId, $field['id'], ucfirst($kind).'ProjectCustomField', $bundle['id'], $bundleType);
        }
    }

    /**
     * Find the bundle by name and add the missing values, or create it.
     *
     * @param  'state'|'enum'  $kind
     * @param  list<array<string, mixed>>  $values
     * @return array<array-key, mixed>
     *
     * @throws YouTrackException
     */
    private function ensureBundle(string $kind, string $bundleName, array $values): array
    {
        $bundle = $this->findBundle($kind, 'name', $bundleName);

        if ($bundle === null) {
            $this->record('bundle', $bundleName, SetupStatus::Create, $kind.': '.implode(', ', array_column($values, 'name')));

            return $this->dryRun ? [] : $this->client->createBundle($kind, $bundleName, $values);
        }

        $this->record('bundle', $bundleName, SetupStatus::Exists);
        $this->ensureBundleValues($kind, $bundle, $values);

        return $bundle;
    }

    /**
     * Whether another project uses the bundle for the field, or new projects get it by default.
     *
     * @throws YouTrackException
     */
    private function isShared(string $fieldName, string $projectId, string $bundleId): bool
    {
        $field = $this->globalField($fieldName) ?? [];

        if (is_array($field['fieldDefaults'] ?? null) && is_array($field['fieldDefaults']['bundle'] ?? null) && ($field['fieldDefaults']['bundle']['id'] ?? null) === $bundleId) {
            return true;
        }

        foreach (is_array($field['instances'] ?? null) ? $field['instances'] : [] as $instance) {
            if (is_array($instance) && ($instance['bundle']['id'] ?? null) === $bundleId && ($instance['project']['id'] ?? null) !== $projectId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<array-key, mixed>  $bundle
     * @param  list<array<string, mixed>>  $values
     *
     * @throws YouTrackException
     */
    private function ensureBundleValues(string $kind, array $bundle, array $values): void
    {
        $present = array_map(
            fn (mixed $value): string => is_array($value) && is_string($value['name'] ?? null) ? $value['name'] : '',
            is_array($bundle['values'] ?? null) ? $bundle['values'] : [],
        );
        $bundleName = (string) ($bundle['name'] ?? $bundle['id'] ?? '');

        foreach ($values as $value) {
            if (in_array($value['name'], $present, true)) {
                continue;
            }

            $this->record('bundle', $bundleName, SetupStatus::Update, 'add value '.(string) $value['name']);

            if (! $this->dryRun && is_string($bundle['id'] ?? null)) {
                $this->client->addBundleValue($kind, $bundle['id'], $value);
            }
        }
    }

    /**
     * @param  list<string>  $names
     *
     * @throws YouTrackException
     */
    private function ensureTags(array $names): void
    {
        foreach ($names as $name) {
            $exists = array_filter($this->client->tags($name), fn (array $tag): bool => ($tag['name'] ?? null) === $name) !== [];

            $this->record('tag', $name, $exists ? SetupStatus::Exists : SetupStatus::Create);

            if (! $exists && ! $this->dryRun) {
                $this->client->createTag($name);
            }
        }
    }

    /**
     * @param  array<string, string>  $searches
     *
     * @throws YouTrackException
     */
    private function ensureSavedSearches(array $searches): void
    {
        $existing = [];

        foreach ($this->client->savedQueries() as $query) {
            if (is_string($query['name'] ?? null)) {
                $existing[$query['name']] ??= (string) ($query['query'] ?? '');
            }
        }

        foreach ($searches as $name => $query) {
            if (! array_key_exists($name, $existing)) {
                $this->record('saved search', $name, SetupStatus::Create, $query);

                if (! $this->dryRun) {
                    $this->client->createSavedQuery($name, $query);
                }

                continue;
            }

            $detail = match (true) {
                $existing[$name] === $query => '',
                self::withoutSorting($existing[$name]) === $query => 'own sorting kept',
                default => 'query differs, kept: '.$existing[$name],
            };

            $this->record('saved search', $name, SetupStatus::Exists, $detail);
        }
    }

    /**
     * @return array<string, string>
     *
     * @throws YouTrackException
     */
    private function ensureArticles(string $project, string $projectId, Placeholders $placeholders): array
    {
        $byTitle = [];

        foreach ($this->client->articles('project: '.$project) as $article) {
            if (is_string($article['summary'] ?? null) && ($article['project']['shortName'] ?? $project) === $project) {
                $byTitle[$article['summary']][] = $article;
            }
        }

        $ids = [];
        $internalIds = [];

        foreach ($this->knowledgeBase->keys() as $key) {
            $title = $this->knowledgeBase->title($key);
            $parent = $this->knowledgeBase->parent($key);
            $parentId = $parent === null ? null : ($internalIds[$parent] ?? null);
            $candidates = $byTitle[$title] ?? [];
            $article = array_values(array_filter(
                $candidates,
                fn (array $candidate): bool => ($candidate['parentArticle']['id'] ?? null) === $parentId,
            ))[0] ?? $candidates[0] ?? null;

            if ($article === null) {
                $this->record('article', $title, SetupStatus::Create, $parent === null ? 'top level' : 'under «'.$this->knowledgeBase->title($parent).'»');

                if ($this->dryRun) {
                    continue;
                }

                $article = $this->client->createArticle(
                    $projectId,
                    $title,
                    $this->knowledgeBase->content($key, $placeholders->withKb($ids)),
                    $parentId,
                );
            } else {
                $this->record('article', $title, SetupStatus::Exists, (string) ($article['idReadable'] ?? ''));
            }

            if (is_string($article['idReadable'] ?? null)) {
                $ids[$key] = $article['idReadable'];
            }

            if (is_string($article['id'] ?? null)) {
                $internalIds[$key] = $article['id'];
            }
        }

        return $ids;
    }

    /**
     * @return array<array-key, mixed>|null
     *
     * @throws YouTrackException
     */
    private function findBundle(string $kind, string $key, mixed $value): ?array
    {
        $this->bundles[$kind] ??= $this->client->bundles($kind);

        foreach ($this->bundles[$kind] as $bundle) {
            if (($bundle[$key] ?? null) === $value) {
                return $bundle;
            }
        }

        return null;
    }

    /**
     * @return array<array-key, mixed>|null
     *
     * @throws YouTrackException
     */
    private function globalField(string $name): ?array
    {
        $this->globalFields ??= $this->client->customFields();

        foreach ($this->globalFields as $field) {
            if (($field['name'] ?? null) === $name) {
                return $field;
            }
        }

        return null;
    }

    private static function withoutSorting(string $query): string
    {
        return trim((string) preg_replace('/\s+sort by:.*$/is', '', $query));
    }

    private function record(string $subject, string $name, SetupStatus $status, string $detail = ''): void
    {
        $this->actions[] = new SetupAction($subject, $name, $status, $detail);
    }
}
