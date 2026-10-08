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
 * localized name, and the missing values are added to its bundle. The values YouTrack gives Stage and the
 * cycle does not use (Develop, Test, Staging) are removed from the project's own bundle, unless issues of
 * the project are in them (reported). A bundle the project shares with other projects, or the default
 * bundle new projects get, is never changed: an empty project is switched to a bundle of its own
 * ("<KEY> Stages"), a project with issues gets a warning. New issues default to Stage Backlog and Type Task
 * when the field has no default among the cycle's values. A State field («Состояние») is not used by the
 * cycle: it is reported and left as is.
 *
 * Board: the project must have an agile board with columns by Stage and swimlanes by Type. The setup only
 * checks it (a board is the team's own view): a project without such a board is an error the human fixes in
 * YouTrack before running the setup again.
 *
 * English names: the agents read the values through the MCP server, which answers with the localized name
 * of a value (a Russian YouTrack shows Backlog as «Очередь»), and refer to the fields by name. So the cycle's
 * values in the project's own bundle lose their localized name, a value named in another language («Очередь»)
 * is renamed to the cycle's name (its issues keep it), and a field named in another language («Этап») is
 * renamed to Stage or Type.
 *
 * Fields, bundles, tags, saved searches and the project id need the REST API (the MCP server has no tools
 * for them); the knowledge base articles are found, created and updated through the MCP server. Existing
 * entities are never moved, only the Stage values above are deleted and only the fields and values above
 * are renamed; an existing article
 * is never changed, except the automation guide, which is a copy of the manual of the installed agentio version.
 */
final class YouTrackSetup
{
    /** Names of a field in other UI languages: the Type field of a Russian YouTrack is «Тип». */
    public const array FIELD_ALIASES = ['Stage' => ['Этап'], 'Type' => ['Тип'], 'State' => ['Состояние']];

    /** Names of the cycle's values in other UI languages, as YouTrack or a team names them in Russian. */
    public const array VALUE_ALIASES = [
        'Backlog' => ['Очередь', 'Бэклог'],
        'Analysis' => ['Анализ', 'Аналитика'],
        'Ready' => ['Готово к разработке', 'Готова к разработке', 'Готов к разработке'],
        'In Progress' => ['В работе', 'В обработке', 'В процессе'],
        'Review' => ['Ревью', 'На ревью', 'На проверке'],
        'Blocked' => ['Заблокирована', 'Заблокировано', 'Заблокирован'],
        'On Hold' => ['On hold', 'Отложено', 'Отложена', 'На паузе', 'Приостановлено'],
        'Done' => ['Готово', 'Выполнено', 'Сделано'],
        'Idea' => ['Идея'],
        'Epic' => ['Эпик', 'Веха'],
        'Story' => ['История', 'Пользовательская история'],
        'Task' => ['Задача', 'Задание'],
    ];

    /** The status field of other YouTrack projects; the cycle keeps the status in Stage (State::FIELD). */
    public const string UNUSED_STATE = 'State';

    /** The values YouTrack gives the Stage field of a new project that the cycle does not use. */
    public const array DEFAULT_STAGES = ['Develop', 'Test', 'Staging'];

    /** How to set up the board the cycle requires, for the human. */
    public const string BOARD_HINT = 'create an agile board for the project in YouTrack (or change one): in the board settings, Columns and rows → columns by the Stage field, a column per value (Backlog, Analysis, Ready, In Progress, Review, Blocked, On Hold, Done), and swimlanes by the Type field (Idea, Epic, Story, Task); then run php artisan agentio:setup-youtrack again';

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
            $project.': на паузе и отложенные (On Hold)' => "project: {$project} Stage: {On Hold}",
            $project.': отложенные идеи' => "project: {$project} tag: ".Tag::Parked->value,
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
        $this->checkBoard($project);

        $this->ensureTags([Tag::Idea->value, Tag::Parked->value, Tag::Claimed->value]);
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
            $attached['field'] = $this->renameField((array) $attached['field'], $name);
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

                if ($name === State::FIELD) {
                    $this->removeDefaultStages($project, $full);
                }

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
            $field = $this->renameField($field, $name);
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
     * Rename a field found by its name in another language («Этап») to the name the cycle refers to: the MCP
     * server and the saved searches know a field by its name. The field is global, so other projects that
     * use it see the new name too.
     *
     * @param  array<array-key, mixed>  $field  The custom field: {id, name, localizedName, fieldType}
     * @return array<array-key, mixed> The field with the new name
     *
     * @throws YouTrackException
     */
    private function renameField(array $field, string $name): array
    {
        $this->record('field', $name, SetupStatus::Update, sprintf('rename the field «%s» to %s (a global field: every project that uses it sees the new name)', (string) ($field['name'] ?? '?'), $name));

        if (! $this->dryRun && is_string($field['id'] ?? null)) {
            $this->client->updateCustomField($field['id'], ['name' => $name]);
        }

        if ($this->globalFields !== null) {
            $this->globalFields = array_map(
                fn (array $global): array => ($global['id'] ?? null) === ($field['id'] ?? false) ? [...$global, 'name' => $name] : $global,
                $this->globalFields,
            );
        }

        return [...$field, 'name' => $name];
    }

    /**
     * Remove the values YouTrack gives Stage that the cycle does not use (Develop, Test, Staging) from the
     * project's own bundle: an agent or a human would move an issue into a status the cycle never leaves. A
     * value issues of the project are in is kept and reported: deleting it would empty their Stage. Runs after
     * ensureDefault(), so the default of new issues is never one of these values.
     *
     * @param  array<array-key, mixed>  $bundle  The project's own Stage bundle with its values
     *
     * @throws YouTrackException
     */
    private function removeDefaultStages(string $project, array $bundle): void
    {
        $bundleName = (string) ($bundle['name'] ?? $bundle['id'] ?? '');

        foreach (is_array($bundle['values'] ?? null) ? $bundle['values'] : [] as $value) {
            if (! is_array($value) || ! is_string($value['name'] ?? null) || ! in_array($value['name'], self::DEFAULT_STAGES, true)) {
                continue;
            }

            $name = $value['name'];

            if ($this->client->hasIssues(sprintf('project: %s %s: {%s}', $project, State::FIELD, $name))) {
                $this->record('bundle', $bundleName, SetupStatus::Warning, "value {$name} is not used by the cycle but issues of the project are in it, so it is kept: move them to a stage of the cycle (a search by Stage: {$name}) and run the setup again");

                continue;
            }

            $this->record('bundle', $bundleName, SetupStatus::Update, "remove value {$name} (YouTrack's own, not used by the cycle)");

            if (! $this->dryRun && is_string($bundle['id'] ?? null) && is_string($value['id'] ?? null)) {
                $this->client->deleteBundleValue('state', $bundle['id'], $value['id']);
            }
        }
    }

    /**
     * The project must have an agile board with columns by Stage and swimlanes by Type: the human follows the
     * cycle on it. Only checked, never created or changed; a board of several projects counts too.
     *
     * @throws YouTrackException
     */
    private function checkBoard(string $project): void
    {
        $problems = [];

        foreach ($this->client->agiles() as $board) {
            $projects = array_map(fn (mixed $item): mixed => is_array($item) ? ($item['shortName'] ?? null) : null, is_array($board['projects'] ?? null) ? $board['projects'] : []);

            if (! in_array($project, $projects, true)) {
                continue;
            }

            $name = (string) ($board['name'] ?? $board['id'] ?? '?');
            $columns = is_array($board['columnSettings'] ?? null) ? $board['columnSettings'] : [];
            $columnField = is_array($columns['field'] ?? null) && is_string($columns['field']['name'] ?? null) ? $columns['field']['name'] : null;
            $swimlaneField = self::swimlaneField($board);
            $wrong = [];

            if (! self::isField($columnField, State::FIELD)) {
                $wrong[] = 'columns by '.($columnField ?? 'no field').' instead of Stage';
            }

            if (! self::isField($swimlaneField, IssueType::FIELD)) {
                $wrong[] = $swimlaneField === null ? 'no swimlanes by a field' : "swimlanes by {$swimlaneField} instead of Type";
            }

            if ($wrong !== []) {
                $problems[] = "«{$name}»: ".implode(', ', $wrong);

                continue;
            }

            $shown = [];

            foreach (is_array($columns['columns'] ?? null) ? $columns['columns'] : [] as $column) {
                foreach (is_array($column) && is_array($column['fieldValues'] ?? null) ? $column['fieldValues'] : [] as $value) {
                    $shown[] = is_array($value) ? ($value['name'] ?? null) : null;
                }
            }

            $missing = array_values(array_filter(array_map(fn (State $state): string => $state->value, State::cases()), fn (string $stage): bool => ! in_array($stage, $shown, true)));

            $missing === []
                ? $this->record('board', $name, SetupStatus::Exists, 'columns by Stage, swimlanes by Type')
                : $this->record('board', $name, SetupStatus::Warning, 'columns by Stage, swimlanes by Type, but no column for '.implode(', ', $missing).': the issues in these stages are not on the board; add the columns in the board settings');

            return;
        }

        $this->record('board', $project, SetupStatus::Error, ($problems === [] ? 'the project has no agile board' : 'no board of the project has columns by Stage and swimlanes by Type ('.implode('; ', $problems).')').': '.self::BOARD_HINT);
    }

    /**
     * The custom field the swimlanes of a board are built on, or null without swimlanes by a field.
     *
     * @param  array<array-key, mixed>  $board
     */
    private static function swimlaneField(array $board): ?string
    {
        $settings = is_array($board['swimlaneSettings'] ?? null) ? $board['swimlaneSettings'] : [];

        if (($settings['$type'] ?? null) !== 'AttributeBasedSwimlaneSettings' || ($settings['enabled'] ?? true) === false || ! is_array($settings['field'] ?? null)) {
            return null;
        }

        $field = $settings['field'];
        $custom = is_array($field['customField'] ?? null) ? $field['customField'] : [];
        $name = $custom['name'] ?? $field['name'] ?? null;

        return is_string($name) ? $name : null;
    }

    /**
     * Whether a field name is the field, or one of its names in other UI languages («Этап», «Тип»).
     */
    private static function isField(?string $name, string $field): bool
    {
        return $name !== null && ($name === $field || in_array($name, self::FIELD_ALIASES[$field] ?? [], true));
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
     * Give every value of the cycle its English name in the bundle: a value with a localized name is shown by
     * its name, a value named in another language is renamed, a missing value is added.
     *
     * @param  array<array-key, mixed>  $bundle
     * @param  list<array<string, mixed>>  $values
     * @return list<mixed> The values of the bundle, the renamed and added ones included
     *
     * @throws YouTrackException
     */
    private function ensureBundleValues(string $kind, array $bundle, array $values): array
    {
        $all = is_array($bundle['values'] ?? null) ? array_values($bundle['values']) : [];
        $names = array_map(fn (mixed $value): string => is_array($value) && is_string($value['name'] ?? null) ? $value['name'] : '', $all);
        $bundleName = (string) ($bundle['name'] ?? $bundle['id'] ?? '');
        $cycle = array_map(fn (array $value): string => (string) $value['name'], $values);

        foreach ($values as $value) {
            $name = (string) $value['name'];
            $key = array_search($name, $names, true);

            if ($key === false) {
                $key = $this->localizedValue($all, $name, $cycle);

                if ($key !== null) {
                    $this->record('bundle', $bundleName, SetupStatus::Update, sprintf('rename value «%s» to %s', $names[$key], $name));
                    $all[$key] = $this->updateValue($kind, $bundle, (array) $all[$key], ['name' => $name, 'localizedName' => null]);
                    $names[$key] = $name;
                }

                if ($key === null) {
                    $this->record('bundle', $bundleName, SetupStatus::Update, 'add value '.$name);

                    if (! $this->dryRun && is_string($bundle['id'] ?? null)) {
                        $all[] = $this->client->addBundleValue($kind, $bundle['id'], $value);
                    }
                }

                continue;
            }

            $localized = is_array($all[$key]) ? ($all[$key]['localizedName'] ?? null) : null;

            if (is_string($localized) && $localized !== '' && $localized !== $name) {
                $this->record('bundle', $bundleName, SetupStatus::Update, sprintf('show value %s by its name instead of «%s»', $name, $localized));
                $all[$key] = $this->updateValue($kind, $bundle, (array) $all[$key], ['localizedName' => null]);
            }
        }

        return array_values($all);
    }

    /**
     * The key of the value named, or shown, in another language as the cycle's value (e.g. «Очередь» for
     * Backlog), skipping the values that carry a name of the cycle.
     *
     * @param  array<int, mixed>  $all  The values of the bundle
     * @param  list<string>  $cycle  The names of the cycle's values
     */
    private function localizedValue(array $all, string $name, array $cycle): ?int
    {
        $aliases = self::VALUE_ALIASES[$name] ?? [];

        foreach ($all as $key => $value) {
            if (! is_array($value) || in_array($value['name'] ?? null, $cycle, true) || ($value['archived'] ?? false) === true) {
                continue;
            }

            if (in_array($value['name'] ?? null, $aliases, true) || in_array($value['localizedName'] ?? null, [$name, ...$aliases], true)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @param  array<array-key, mixed>  $bundle
     * @param  array<array-key, mixed>  $value
     * @param  array<string, mixed>  $changes
     * @return array<array-key, mixed> The value with the changes
     *
     * @throws YouTrackException
     */
    private function updateValue(string $kind, array $bundle, array $value, array $changes): array
    {
        if (! $this->dryRun && is_string($bundle['id'] ?? null) && is_string($value['id'] ?? null)) {
            $this->client->updateBundleValue($kind, $bundle['id'], $value['id'], $changes);
        }

        return [...$value, ...$changes];
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
