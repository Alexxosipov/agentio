<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Install;

use Obrazmisli\Agentio\YouTrack\Client;
use Obrazmisli\Agentio\YouTrack\IssueType;
use Obrazmisli\Agentio\YouTrack\Mcp\Tools;
use Obrazmisli\Agentio\YouTrack\State;
use Obrazmisli\Agentio\YouTrack\Tag;
use Obrazmisli\Agentio\YouTrack\YouTrackException;

/**
 * Configures a YouTrack project for the autonomous cycle, idempotently, so it can run any number of times:
 * the values of the project's own Stage and Type fields, the idea / agent-claimed tags, the "<KEY>: …" saved
 * searches and the knowledge base tree.
 *
 * Fields: the cycle uses the fields YouTrack gives new projects — Stage (the status of an issue) and Type
 * («Тип») — and never creates a second field of the same meaning: a field is found by its name or its
 * localized name, and only the missing values are added to its bundle (Stage keeps its own values, e.g.
 * Develop or Test, next to the cycle's). A bundle the project shares with other projects, or the default
 * bundle new projects get, is never changed: an empty project is switched to a bundle of its own
 * ("<KEY> Stages"), a project with issues gets a warning. New issues default to Stage Backlog and Type Task
 * when the field has no default among the cycle's values. A State field («Состояние») is not used by the
 * cycle: it is reported and left as is.
 *
 * Fields, bundles, tags, saved searches and the project id need the REST API (the MCP server has no tools
 * for them); the knowledge base articles are found, created and updated through the MCP server. Existing
 * entities are never deleted, renamed or moved; an existing article is never changed, except the automation
 * guide, which is a copy of the manual of the installed agentio version.
 */
final class YouTrackSetup
{
    /** Names of a field in other UI languages: the Type field of a Russian YouTrack is «Тип». */
    public const array FIELD_ALIASES = ['Stage' => ['Этап'], 'Type' => ['Тип'], 'State' => ['Состояние']];

    /** The status field of other YouTrack projects; the cycle keeps the status in Stage (State::FIELD). */
    public const string UNUSED_STATE = 'State';

    /** @var list<SetupAction> */
    private array $actions = [];

    /** @var array<string, list<array<array-key, mixed>>> */
    private array $bundles = [];

    /** @var list<array<array-key, mixed>>|null */
    private ?array $globalFields = null;

    public function __construct(
        private readonly Client $client,
        private readonly Tools $tools,
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
            $project.': идеи без плана' => "project: {$project} tag: idea Stage: Backlog tag: -{{$claimed}}",
            $project.': готовые эпики' => "project: {$project} Type: Epic Stage: Ready tag: -{{$claimed}}",
            $project.': готовые задачи' => "project: {$project} Type: Task Stage: Ready tag: -{{$claimed}}",
            $project.': заблокированные (причина в [AGENT:BLOCKED])' => "project: {$project} Stage: Blocked",
            $project.': в работе у агентов' => "project: {$project} tag: {{$claimed}}",
            $project.': эпики на приёмке' => "project: {$project} Type: Epic Stage: Review",
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
        $fields = array_values(array_filter($this->client->projectCustomFields($projectId), fn (array $field): bool => is_array($field['field'] ?? null)));

        $states = array_map(fn (State $state): array => ['name' => $state->value, 'isResolved' => $state === State::Done], State::cases());
        $types = array_map(fn (IssueType $type): array => ['name' => $type->value], IssueType::cases());

        $this->ensureField($project, $projectId, $fields, State::FIELD, 'state', $project.' Stages', $states, State::Backlog->value);
        $this->ensureField($project, $projectId, $fields, 'Type', 'enum', $project.' Types', $types, IssueType::Task->value);
        $this->unusedState($fields);

        $this->ensureTags([Tag::Idea->value, Tag::Claimed->value]);
        $this->ensureSavedSearches(self::savedSearches($project));

        return $this->ensureArticles($project, $placeholders);
    }

    /**
     * @return list<SetupAction>
     */
    public function actions(): array
    {
        return $this->actions;
    }

    /**
     * @param  list<array<array-key, mixed>>  $projectFields  The project custom fields
     * @param  'state'|'enum'  $kind
     * @param  list<array<string, mixed>>  $values
     *
     * @throws YouTrackException
     */
    private function ensureField(string $project, string $projectId, array $projectFields, string $name, string $kind, string $bundleName, array $values, string $default): void
    {
        $bundleType = $kind === 'state' ? 'StateBundle' : 'EnumBundle';
        $fieldType = ucfirst($kind).'ProjectCustomField';
        $attached = $this->matching(array_map(fn (array $field): array => (array) $field['field'], $projectFields), $name, $kind);
        $attached = $attached === null ? null : $projectFields[$attached];

        if ($attached !== null && ($attached['field']['name'] ?? null) !== $name) {
            $this->record('field', $name, SetupStatus::Warning, sprintf(
                'the project has it as «%s»: agentio refers to the field as %s, rename it back in YouTrack (no second field is created)',
                (string) ($attached['field']['name'] ?? '?'),
                $name,
            ));

            return;
        }

        if ($attached !== null) {
            $bundle = is_array($attached['bundle'] ?? null) ? $attached['bundle'] : [];

            if (($bundle['$type'] ?? $bundleType) !== $bundleType || ! is_string($bundle['id'] ?? null)) {
                $this->record('field', $name, SetupStatus::Warning, sprintf('attached with a %s bundle, expected %s; left as is', (string) ($bundle['$type'] ?? 'missing'), $bundleType));

                return;
            }

            $current = (string) ($bundle['name'] ?? $bundle['id']);

            if (! $this->isShared($name, $projectId, $bundle['id'])) {
                $localized = $attached['field']['localizedName'] ?? null;
                $this->record('field', $name, SetupStatus::Exists, 'bundle '.$current.(is_string($localized) && $localized !== '' && $localized !== $name ? ", shown as «{$localized}»" : ''));
                $full = $this->findBundle($kind, 'id', $bundle['id']) ?? $bundle;
                $full['values'] = $this->ensureBundleValues($kind, $full, $values);
                $this->ensureDefault($projectId, $attached, $name, $full, $values, $default, $bundleType);

                return;
            }

            if (! is_string($attached['id'] ?? null) || $this->client->projectHasIssues($project)) {
                $this->record('field', $name, SetupStatus::Warning, "bundle {$current} is shared with other projects (or is the default of new projects), so it is not changed: give the project a bundle of its own with the values ".implode(', ', array_column($values, 'name')));

                return;
            }

            $own = $this->ensureBundle($kind, $bundleName, $values);
            $this->record('field', $name, SetupStatus::Update, "switch from the shared bundle {$current} to {$bundleName}");

            if (! $this->dryRun && is_string($own['id'] ?? null)) {
                $type = (string) ($attached['$type'] ?? $fieldType);
                // Without clearing the default first, YouTrack copies the old default value into the new bundle.
                $this->client->updateProjectCustomField($projectId, $attached['id'], $type, ['defaultValues' => [], 'canBeEmpty' => true]);
                $this->client->updateProjectCustomField($projectId, $attached['id'], $type, [
                    'bundle' => ['id' => $own['id'], '$type' => $bundleType],
                    'defaultValues' => $this->defaultValues($own, $default, $bundleType),
                    'canBeEmpty' => false,
                ]);
            }

            return;
        }

        $field = $this->globalField($name, $kind);
        $globalType = $kind.'[1]';

        if ($field !== null && ($field['fieldType']['id'] ?? null) !== $globalType) {
            $this->record('field', $name, SetupStatus::Warning, sprintf('a global field of type %s exists, expected %s; not attached', (string) ($field['fieldType']['id'] ?? '?'), $globalType));

            return;
        }

        if ($field !== null && ($field['name'] ?? null) !== $name) {
            $this->record('field', $name, SetupStatus::Warning, sprintf('YouTrack has it as «%s»: rename it back to %s and run the setup again (no second field is created)', (string) ($field['name'] ?? '?'), $name));

            return;
        }

        $bundle = $this->ensureBundle($kind, $bundleName, $values);

        if ($field === null) {
            $this->record('field', $name.' (global)', SetupStatus::Create, $globalType);
            $field = $this->dryRun ? [] : $this->client->createCustomField($name, $globalType);
        }

        $this->record('field', $name, SetupStatus::Create, "attach to the project with bundle {$bundleName}, default {$default}");

        if (! $this->dryRun && is_string($field['id'] ?? null) && is_string($bundle['id'] ?? null)) {
            $defaultId = $this->defaultValues($bundle, $default, $bundleType)[0]['id'] ?? null;
            $this->client->attachCustomField($projectId, $field['id'], $fieldType, $bundle['id'], $bundleType, $defaultId === null, $defaultId);
        }
    }

    /**
     * A State field («Состояние») attached to the project is not read by the cycle: reported, never changed.
     *
     * @param  list<array<array-key, mixed>>  $projectFields
     */
    private function unusedState(array $projectFields): void
    {
        if ($this->matching(array_map(fn (array $field): array => (array) $field['field'], $projectFields), self::UNUSED_STATE, 'state') !== null) {
            $this->record('field', self::UNUSED_STATE, SetupStatus::Warning, 'not used by agentio: the cycle keeps the status of an issue in Stage; build the board columns on Stage and set Stage on issues that only have a State');
        }
    }

    /**
     * The key of the field with the name, or else with one of its localized names, of the kind.
     *
     * @param  list<array<array-key, mixed>>  $fields  Custom fields: {name, localizedName, fieldType: {id}}
     * @param  'state'|'enum'  $kind
     */
    private function matching(array $fields, string $name, string $kind): ?int
    {
        foreach ($fields as $key => $field) {
            if (($field['name'] ?? null) === $name) {
                return $key;
            }
        }

        $aliases = self::FIELD_ALIASES[$name] ?? [];

        foreach ($fields as $key => $field) {
            $type = is_array($field['fieldType'] ?? null) ? ($field['fieldType']['id'] ?? null) : null;
            $named = in_array($field['name'] ?? null, $aliases, true) || in_array($field['localizedName'] ?? null, [$name, ...$aliases], true);

            if ($named && ($type === null || $type === $kind.'[1]')) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Make new issues get the cycle's default value when the field's default is not one of the cycle's values.
     *
     * @param  array<array-key, mixed>  $attached  The project custom field
     * @param  array<array-key, mixed>  $bundle  Its bundle with the values
     * @param  list<array<string, mixed>>  $values
     *
     * @throws YouTrackException
     */
    private function ensureDefault(string $projectId, array $attached, string $name, array $bundle, array $values, string $default, string $bundleType): void
    {
        $current = array_map(
            fn (mixed $value): string => is_array($value) && is_string($value['name'] ?? null) ? $value['name'] : '',
            is_array($attached['defaultValues'] ?? null) ? $attached['defaultValues'] : [],
        );

        if ($current !== [] && array_diff($current, array_column($values, 'name')) === []) {
            return;
        }

        $this->record('field', $name, SetupStatus::Update, sprintf('default value %s → %s', $current === [] ? 'none' : implode(', ', $current), $default));
        $defaults = $this->defaultValues($bundle, $default, $bundleType);

        if (! $this->dryRun && is_string($attached['id'] ?? null) && $defaults !== []) {
            $this->client->updateProjectCustomField($projectId, $attached['id'], (string) ($attached['$type'] ?? ''), ['defaultValues' => $defaults]);
        }
    }

    /**
     * The element of the bundle with that name, as the defaultValues of a project custom field.
     *
     * @param  array<array-key, mixed>  $bundle
     * @return list<array{id: string, '$type': string}>
     */
    private function defaultValues(array $bundle, string $name, string $bundleType): array
    {
        foreach (is_array($bundle['values'] ?? null) ? $bundle['values'] : [] as $value) {
            if (is_array($value) && ($value['name'] ?? null) === $name && is_string($value['id'] ?? null)) {
                return [['id' => $value['id'], '$type' => $bundleType.'Element']];
            }
        }

        return [];
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
        $bundle['values'] = $this->ensureBundleValues($kind, $bundle, $values);

        return $bundle;
    }

    /**
     * Whether another project uses the bundle for the field, or new projects get it by default.
     *
     * @throws YouTrackException
     */
    private function isShared(string $fieldName, string $projectId, string $bundleId): bool
    {
        $field = $this->globalField($fieldName, null) ?? [];

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
     * @return list<mixed> The values of the bundle, the added ones included
     *
     * @throws YouTrackException
     */
    private function ensureBundleValues(string $kind, array $bundle, array $values): array
    {
        $all = is_array($bundle['values'] ?? null) ? array_values($bundle['values']) : [];
        $present = array_map(fn (mixed $value): string => is_array($value) && is_string($value['name'] ?? null) ? $value['name'] : '', $all);
        $bundleName = (string) ($bundle['name'] ?? $bundle['id'] ?? '');

        foreach ($values as $value) {
            if (in_array($value['name'], $present, true)) {
                continue;
            }

            $this->record('bundle', $bundleName, SetupStatus::Update, 'add value '.(string) $value['name']);

            if (! $this->dryRun && is_string($bundle['id'] ?? null)) {
                $all[] = $this->client->addBundleValue($kind, $bundle['id'], $value);
            }
        }

        return $all;
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
     * Find (preferably under the expected parent) or create the knowledge base articles through the MCP server.
     *
     * @return array<string, string>
     *
     * @throws YouTrackException
     */
    private function ensureArticles(string $project, Placeholders $placeholders): array
    {
        $byTitle = [];

        foreach ($this->tools->searchArticles('project: '.$project) as $article) {
            if (is_string($article['summary'] ?? null) && is_string($article['id'] ?? null) && str_starts_with($article['id'], $project.'-')) {
                $parent = is_array($article['parentArticle'] ?? null) ? ($article['parentArticle']['id'] ?? null) : null;
                $byTitle[$article['summary']][] = ['id' => $article['id'], 'parent' => is_string($parent) ? $parent : null];
            }
        }

        $ids = [];
        $guide = null;

        foreach ($this->knowledgeBase->keys() as $key) {
            $title = $this->knowledgeBase->title($key);
            $parent = $this->knowledgeBase->parent($key);
            $parentId = $parent === null ? null : ($ids[$parent] ?? null);
            $candidates = $byTitle[$title] ?? [];
            usort($candidates, fn (array $left, array $right): int => Tools::number($left['id']) <=> Tools::number($right['id']));
            $article = array_values(array_filter($candidates, fn (array $candidate): bool => $candidate['parent'] === $parentId))[0] ?? $candidates[0] ?? null;

            if ($key === KnowledgeBase::GUIDE) {
                // The guide refers to the other articles (and to itself): it is written once all ids are known.
                $guide = ['article' => $article, 'parentId' => $parentId];

                if ($article !== null) {
                    $ids[$key] = $article['id'];
                }

                continue;
            }

            if ($article === null) {
                $this->record('article', $title, SetupStatus::Create, $parent === null ? 'top level' : 'under «'.$this->knowledgeBase->title($parent).'»');

                if (! $this->dryRun) {
                    $ids[$key] = $this->tools->createArticle($project, $title, $this->knowledgeBase->content($key, $placeholders->withKb($ids)), $parentId);
                }

                continue;
            }

            $this->record('article', $title, SetupStatus::Exists, $article['id']);
            $ids[$key] = $article['id'];
        }

        if ($guide !== null) {
            $ids = $this->ensureGuide($project, $guide['article'], $guide['parentId'], $ids, $placeholders);
        }

        return $ids;
    }

    /**
     * The automation guide is a copy of the manual of the installed agentio version: created, or updated when
     * its content differs.
     *
     * @param  array{id: string, parent: string|null}|null  $article
     * @param  array<string, string>  $ids
     * @return array<string, string>
     *
     * @throws YouTrackException
     */
    private function ensureGuide(string $project, ?array $article, ?string $parentId, array $ids, Placeholders $placeholders): array
    {
        $key = KnowledgeBase::GUIDE;
        $title = $this->knowledgeBase->title($key);
        $parent = $this->knowledgeBase->parent($key);

        if ($article === null) {
            $this->record('article', $title, SetupStatus::Create, $parent === null ? 'top level' : 'under «'.$this->knowledgeBase->title($parent).'»');

            if ($this->dryRun) {
                return $ids;
            }

            $created = $this->knowledgeBase->content($key, $placeholders->withKb($ids));
            $ids[$key] = $this->tools->createArticle($project, $title, $created, $parentId);
            $content = $this->knowledgeBase->content($key, $placeholders->withKb($ids));

            if ($content !== $created) {
                $this->tools->updateArticleContent($ids[$key], $content);
            }

            return $ids;
        }

        $content = $this->knowledgeBase->content($key, $placeholders->withKb($ids));

        if (self::normalized($this->tools->articleContent($article['id'])) === self::normalized($content)) {
            $this->record('article', $title, SetupStatus::Exists, $article['id']);

            return $ids;
        }

        $this->record('article', $title, SetupStatus::Update, $article['id'].': the manual of the installed agentio version');

        if (! $this->dryRun) {
            $this->tools->updateArticleContent($article['id'], $content);
        }

        return $ids;
    }

    private static function normalized(string $content): string
    {
        return trim(str_replace("\r\n", "\n", $content));
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
    private function globalField(string $name, ?string $kind): ?array
    {
        $this->globalFields ??= $this->client->customFields();

        if ($kind === null) {
            foreach ($this->globalFields as $field) {
                if (($field['name'] ?? null) === $name) {
                    return $field;
                }
            }

            return null;
        }

        /** @var 'state'|'enum' $kind */
        $key = $this->matching($this->globalFields, $name, $kind);

        return $key === null ? null : $this->globalFields[$key];
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
