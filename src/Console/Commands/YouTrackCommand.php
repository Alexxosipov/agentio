<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Console\Commands;

use Illuminate\Console\Command;
use Obrazmisli\Agentio\Git\Branches;
use Obrazmisli\Agentio\Process\AgentCommentKind;
use Obrazmisli\Agentio\Process\AgentComments;
use Obrazmisli\Agentio\Process\Claims;
use Obrazmisli\Agentio\Process\ReadinessGraph;
use Obrazmisli\Agentio\Process\StructureValidator;
use Obrazmisli\Agentio\Process\TreeNode;
use Obrazmisli\Agentio\Settings;
use Obrazmisli\Agentio\YouTrack\Issue;
use Obrazmisli\Agentio\YouTrack\IssueType;
use Obrazmisli\Agentio\YouTrack\Mcp\Tools;
use Obrazmisli\Agentio\YouTrack\State;
use Obrazmisli\Agentio\YouTrack\Tag;
use Obrazmisli\Agentio\YouTrack\YouTrackException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Process\Process;

/**
 * The deterministic YouTrack helper of the cycle: the readiness rules, epic trees, graph validation, claims
 * and the knowledge base tree, computed the same way for the loop and for the agents. Everything goes through
 * the YouTrack MCP server (the same tools the agents call), never through the REST API.
 */
#[AsCommand(name: 'agentio:yt')]
final class YouTrackCommand extends Command
{
    public const int LOST = 3;

    public const int INVALID = 2;

    /**
     * @var string
     */
    protected $signature = 'agentio:yt
        {action=help : ideas, ready-epics, claimed-epics, resumable, tree, ready-tasks, blocked, validate, claim, release, state, kb-tree}
        {id? : The issue (or, for kb-tree, the article) id}
        {--json : Print JSON}
        {--as= : claim: owner suffix of a subagent, e.g. its TASK id}
        {--plan= : claim: the plan written into the [AGENT:START] comment}
        {--branch= : claim: the branch (default: the current git branch)}
        {--worktree= : claim: the worktree (default: the current git checkout)}
        {--owner= : claim: the whole owner (default: <host>:<worktree>[#<as>])}
        {--state= : release: the new Stage}
        {--comment= : release: a comment posted before the Stage changes}
        {--depth= : kb-tree: levels below the article}';

    /**
     * @var string
     */
    protected $description = 'YouTrack helper of the agent cycle (readiness, epic trees, claims, knowledge base tree) over the YouTrack MCP server';

    private Tools $tools;

    private Claims $claims;

    private Settings $settings;

    private string $project;

    private ?ReadinessGraph $graph = null;

    public function handle(Tools $tools, Settings $settings): int
    {
        $this->tools = $tools;
        $this->claims = new Claims($tools);
        $this->settings = $settings;
        $this->project = $settings->project();
        $this->graph = null;
        $action = (string) $this->argument('action');

        if ($action === 'help') {
            $this->help();

            return self::SUCCESS;
        }

        if (! $tools->isConfigured()) {
            return $this->failWith('YOUTRACK_URL and YOUTRACK_TOKEN are not set (the environment or .env): run php artisan agentio:install.');
        }

        try {
            return match ($action) {
                'ideas' => $this->ideas(),
                'ready-epics' => $this->readyEpics(),
                'claimed-epics' => $this->claimedEpics(),
                'resumable' => $this->resumable(),
                'tree' => $this->tree($this->id()),
                'ready-tasks' => $this->readyTasks($this->id()),
                'blocked' => $this->blocked(),
                'validate' => $this->validate($this->id()),
                'claim' => $this->claim($this->id()),
                'release' => $this->release($this->id()),
                'state' => $this->state($this->id()),
                'kb-tree' => $this->kbTree($this->stringArgument('id')),
                default => $this->failWith("Unknown action {$action}: run php artisan agentio:yt help."),
            };
        } catch (YouTrackException $exception) {
            return $this->failWith($exception->getMessage());
        }
    }

    /**
     * Ideas waiting for planning: tag idea or Type Idea, Stage Backlog, not claimed.
     */
    private function ideas(): int
    {
        $ideas = [];

        foreach (['tag: {'.Tag::Idea->value.'}', 'Type: '.IssueType::Idea->value] as $filter) {
            foreach ($this->tools->searchIssues($this->query($filter.' '.State::FIELD.': '.State::Backlog->value.' tag: -{'.Tag::Claimed->value.'}')) as $raw) {
                $issue = Issue::fromMcp($raw);

                if ($issue->hasState(State::Backlog)) {
                    $ideas[$issue->id] = ['id' => $issue->id, 'summary' => $issue->summary];
                }
            }
        }

        $ideas = array_map(fn (string $id): array => $ideas[$id], Tools::byNumber(array_keys($ideas)));

        return $this->output($ideas, function (array $ideas): void {
            foreach ($ideas as $idea) {
                $this->line($idea['id'].' '.$idea['summary']);
            }
        });
    }

    /**
     * Epics the loop may start: Ready, not claimed, no unmet dependencies, at least one ready task.
     */
    private function readyEpics(): int
    {
        $epics = [];

        foreach ($this->tools->searchIssues($this->query('Type: Epic '.State::FIELD.': '.State::Ready->value.' tag: -{'.Tag::Claimed->value.'}')) as $raw) {
            $id = (string) ($raw['id'] ?? '');

            if ($id !== '' && $this->graph()->isEpicReady($id)) {
                $summary = $this->graph()->get($id)->summary;
                $epics[] = ['id' => $id, 'summary' => $summary, 'branch' => Branches::forIssue($id), 'readyTasks' => $this->graph()->readyTasks($id)];
            }
        }

        return $this->output($epics, function (array $epics): void {
            foreach ($epics as $epic) {
                $this->line($epic['id'].' ready-tasks='.implode(',', $epic['readyTasks']).' '.$epic['summary']);
            }
        });
    }

    /**
     * Epics with the agent-claimed tag and the owners of their claims.
     */
    private function claimedEpics(): int
    {
        $epics = [];

        foreach ($this->tools->searchIssues($this->query('Type: Epic tag: {'.Tag::Claimed->value.'}')) as $raw) {
            $issue = Issue::fromMcp($raw);
            $epics[] = [
                'id' => $issue->id,
                'state' => $issue->state(),
                'summary' => $issue->summary,
                'owner' => $this->agentComments($issue->id)->claimOwner(),
            ];
        }

        return $this->output($epics, function (array $epics): void {
            foreach ($epics as $epic) {
                $this->line($epic['id'].' '.($epic['state'] ?? '-').' owner='.($epic['owner'] ?? '-').' '.$epic['summary']);
            }
        });
    }

    /**
     * What this machine left unfinished, for the loop to resume: epics In Progress claimed by their worktree here
     * (which still exists), and ideas in planning claimed by the project manager of this checkout.
     */
    private function resumable(): int
    {
        $items = [];
        $worktrees = $this->settings->worktreesPath();

        foreach ($this->tools->searchIssues($this->query('Type: Epic tag: {'.Tag::Claimed->value.'}')) as $raw) {
            $epic = Issue::fromMcp($raw);
            $worktree = $worktrees === null ? null : (realpath($worktrees) ?: $worktrees).'/'.$epic->id;

            if ($worktree !== null && $epic->hasState(State::InProgress) && is_dir($worktree) && $this->claims->ownerOf($epic->id) === Claims::owner($worktree)) {
                $items[$epic->id] = ['id' => $epic->id, 'kind' => 'epic', 'summary' => $epic->summary];
            }
        }

        $planner = Claims::owner(realpath($this->settings->basePath()) ?: $this->settings->basePath(), Claims::PLANNER);

        foreach (['tag: {'.Tag::Idea->value.'}', 'Type: '.IssueType::Idea->value] as $filter) {
            foreach ($this->tools->searchIssues($this->query($filter.' tag: {'.Tag::Claimed->value.'}')) as $raw) {
                $idea = Issue::fromMcp($raw);

                if (! isset($items[$idea->id]) && ($idea->hasState(State::Analysis) || $idea->hasState(State::InProgress)) && $this->claims->ownerOf($idea->id) === $planner) {
                    $items[$idea->id] = ['id' => $idea->id, 'kind' => 'idea', 'summary' => $idea->summary];
                }
            }
        }

        return $this->output(array_values($items), function (array $items): void {
            foreach ($items as $item) {
                $this->line($item['id'].' '.$item['kind'].' '.$item['summary']);
            }
        });
    }

    /**
     * The tree under the issue (EPIC -> STORY -> TASK), depth first, with readiness and unmet dependencies.
     */
    private function tree(string $id): int
    {
        if ($this->graph()->find($id) === null) {
            return $this->failWith("Issue {$id} does not exist.");
        }

        $nodes = array_map(fn (TreeNode $node): array => [
            'id' => $node->issue->id,
            'type' => $node->issue->type(),
            'state' => $node->issue->state(),
            'summary' => $node->issue->summary,
            'depth' => $node->depth,
            'parent' => $node->issue->parentId(),
            'dependsOn' => $node->issue->dependencyIds(),
            'unmetDependencies' => $node->unmetDependencies,
            'claimed' => $node->issue->isClaimed(),
            'ready' => $node->ready,
        ], $this->graph()->tree($id)->flatten());

        return $this->output($nodes, function (array $nodes): void {
            foreach ($nodes as $node) {
                $flags = ($node['ready'] === true ? ' [READY]' : '')
                    .($node['claimed'] ? ' [CLAIMED]' : '')
                    .($node['unmetDependencies'] === [] ? '' : ' waits:'.implode(',', $node['unmetDependencies']));
                $this->line(str_repeat('  ', $node['depth']).$this->issueLine($node['id'], $node['state'], $node['type'], $node['summary']).$flags);
            }
        });
    }

    /**
     * Tasks of the epic that can be started now.
     */
    private function readyTasks(string $epic): int
    {
        $tasks = array_map(fn (string $id): array => [
            'id' => $id,
            'summary' => $this->graph()->get($id)->summary,
            'story' => $this->graph()->parentOf($id),
        ], $this->graph()->readyTasks($epic));

        return $this->output($tasks, function (array $tasks): void {
            foreach ($tasks as $task) {
                $this->line($task['id'].' (story '.($task['story'] ?? '-').') '.$task['summary']);
            }
        });
    }

    /**
     * Blocked issues with the reason of their last [AGENT:BLOCKED], and Ready tasks and epics waiting for
     * dependencies.
     */
    private function blocked(): int
    {
        $blocked = [];

        foreach ($this->tools->searchIssues($this->query(State::FIELD.': '.State::Blocked->value)) as $raw) {
            $issue = Issue::fromMcp($raw);
            $blocked[] = [
                'id' => $issue->id,
                'type' => $issue->type(),
                'summary' => $issue->summary,
                'reason' => $this->agentComments($issue->id)->last(AgentCommentKind::Blocked)->text ?? '(no [AGENT:BLOCKED] comment)',
            ];
        }

        $waiting = [];

        foreach ([IssueType::Task->value, IssueType::Epic->value] as $type) {
            foreach ($this->tools->searchIssues($this->query(IssueType::FIELD.': '.$type.' '.State::FIELD.': '.State::Ready->value)) as $raw) {
                $id = (string) ($raw['id'] ?? '');
                $unmet = $id === '' ? [] : $this->graph()->unmetDependencies($id);

                if ($unmet !== []) {
                    $waiting[] = [
                        'id' => $id,
                        'type' => $type,
                        'summary' => (string) ($raw['summary'] ?? ''),
                        'waitsFor' => array_map(fn (string $dependency): string => $dependency.' ('.($this->graph()->find($dependency)?->state() ?? '?').')', $unmet),
                    ];
                }
            }
        }

        return $this->output(['blocked' => $blocked, 'waitingForDependencies' => $waiting], function (array $data): void {
            $this->line('Blocked (needs a human):');

            foreach ($data['blocked'] as $item) {
                $this->line('  '.$item['id'].' '.$item['summary']);
                $this->line('    '.str_replace("\n", "\n    ", trim($item['reason'])));
            }

            $this->line('Ready but waiting for dependencies:');

            foreach ($data['waitingForDependencies'] as $item) {
                $this->line('  '.$item['id'].' '.$item['summary'].' <- '.implode(', ', $item['waitsFor']));
            }
        });
    }

    /**
     * Structure checks of an epic, or of the epics an idea relates to: prefixes match Type, parents, stories
     * with tasks, a non-empty first wave of a Ready epic, no dependency cycles.
     */
    private function validate(string $root): int
    {
        $issue = $this->graph()->find($root);

        if ($issue === null) {
            return $this->failWith("Issue {$root} does not exist.");
        }

        $epics = $issue->hasType(IssueType::Epic) ? [$root] : Tools::byNumber($this->tools->issueIds($this->query('Type: Epic relates to: '.$root)));
        $problems = $epics === []
            ? ["{$root}: no epics found (an idea is linked to its epics with 'relates to')."]
            : (new StructureValidator($this->graph()))->problems($epics);

        $this->output(['ok' => $problems === [], 'epics' => $epics, 'problems' => $problems], function (array $result): void {
            $this->line(($result['ok'] ? 'OK' : 'PROBLEMS').' epics='.implode(',', $result['epics']));

            foreach ($result['problems'] as $problem) {
                $this->line('  - '.$problem);
            }
        });

        return $problems === [] ? self::SUCCESS : self::INVALID;
    }

    /**
     * Claim the issue (or resume an own claim): [AGENT:START] with owner, branch and worktree, the agent-claimed
     * tag, Stage In Progress, then read the comments again and check that the active claim is ours.
     */
    private function claim(string $id): int
    {
        $worktree = $this->stringOption('worktree') ?? $this->git('rev-parse', '--show-toplevel');
        $branch = $this->stringOption('branch') ?? $this->git('branch', '--show-current');

        if ($worktree === null || $branch === null) {
            return $this->failWith('Cannot determine the git worktree and branch: pass --worktree and --branch.');
        }

        $owner = $this->stringOption('owner') ?? Claims::owner($worktree, $this->stringOption('as'));
        $result = $this->claims->claim($id, $owner, $branch, $worktree, $this->stringOption('plan'));

        if (! $result->won) {
            return $this->lost($id, $result->owner);
        }

        return $this->output(
            ['claimed' => true, 'owner' => $owner, 'resumed' => $result->resumed],
            fn (array $data) => $this->line(($data['resumed'] ? 'RESUMED' : 'CLAIMED')." {$id} as {$owner}"),
        );
    }

    /**
     * Post an optional comment, end the claim, set the Stage and remove the agent-claimed tag.
     */
    private function release(string $id): int
    {
        $state = $this->validState($this->stringOption('state'));

        if ($state === null) {
            return $this->failWith('Usage: agentio:yt release <ID> --state=<Stage> [--comment=<text>]  (Stage: '.$this->states().')');
        }

        $this->claims->release($id, $state, $this->stringOption('comment'));

        return $this->output(['id' => $id, 'state' => $state->value], fn () => $this->line("RELEASED {$id} -> {$state->value}"));
    }

    private function state(string $id): int
    {
        $issue = Issue::fromMcp($this->tools->issue($id));

        return $this->output(
            ['id' => $id, 'type' => $issue->type(), 'state' => $issue->state(), 'claimed' => $issue->isClaimed(), 'summary' => $issue->summary],
            fn (array $result) => $this->line((string) $result['state']),
        );
    }

    /**
     * The knowledge base tree of the project, or of one article, with article ids.
     */
    private function kbTree(?string $root): int
    {
        $articles = [];

        foreach ($this->tools->searchArticles('project: '.$this->project) as $raw) {
            if (is_string($raw['id'] ?? null)) {
                $articles[$raw['id']] = [
                    'summary' => (string) ($raw['summary'] ?? ''),
                    'parent' => is_array($raw['parentArticle'] ?? null) && is_string($raw['parentArticle']['id'] ?? null) ? $raw['parentArticle']['id'] : null,
                ];
            }
        }

        if ($root !== null && ! isset($articles[$root])) {
            return $this->failWith("Article {$root} is not in the knowledge base of project {$this->project}.");
        }

        $depth = is_string($this->option('depth')) ? max(0, (int) $this->option('depth')) : null;
        $tops = $root !== null ? [$root] : array_keys(array_filter($articles, fn (array $article): bool => $article['parent'] === null || ! isset($articles[$article['parent']])));
        $seen = [];
        $tree = array_map(fn (string $id): array => $this->articleNode($articles, $id, $depth, $seen), Tools::byNumber($tops));

        return $this->output($tree, function (array $tree): void {
            $print = function (array $node, int $level) use (&$print): void {
                $this->line(str_repeat('  ', $level).sprintf('%-9s %s', $node['id'], $node['summary']));

                foreach ($node['children'] as $child) {
                    $print($child, $level + 1);
                }
            };

            foreach ($tree as $node) {
                $print($node, 0);
            }
        });
    }

    /**
     * @param  array<string, array{summary: string, parent: string|null}>  $articles
     * @param  array<string, true>  $seen
     * @return array{id: string, summary: string, children: list<array<string, mixed>>}
     */
    private function articleNode(array $articles, string $id, ?int $depth, array &$seen): array
    {
        $seen[$id] = true;
        $children = [];

        if ($depth === null || $depth > 0) {
            $ids = array_keys(array_filter($articles, fn (array $article): bool => $article['parent'] === $id));

            foreach (Tools::byNumber($ids) as $child) {
                if (! isset($seen[$child])) {
                    $children[] = $this->articleNode($articles, $child, $depth === null ? null : $depth - 1, $seen);
                }
            }
        }

        return ['id' => $id, 'summary' => $articles[$id]['summary'], 'children' => $children];
    }

    private function graph(): ReadinessGraph
    {
        return $this->graph ??= new ReadinessGraph([], fn (string $id): ?Issue => $this->tools->issueWithLinks($id));
    }

    /**
     * @throws YouTrackException
     */
    private function agentComments(string $id): AgentComments
    {
        return AgentComments::fromComments($this->tools->comments($id));
    }

    private function lost(string $id, ?string $owner): int
    {
        $this->output(['claimed' => false, 'owner' => $owner], fn () => $this->line("LOST: {$id} is claimed by ".($owner ?? 'nobody (the claim was released)')));

        return self::LOST;
    }

    private function validState(?string $value): ?State
    {
        foreach (State::cases() as $state) {
            if ($value !== null && mb_strtolower($state->value) === mb_strtolower(trim($value))) {
                return $state;
            }
        }

        return null;
    }

    private function states(): string
    {
        return implode(', ', array_map(fn (State $state): string => $state->value, State::cases()));
    }

    private function query(string $filter): string
    {
        return 'project: '.$this->project.' '.$filter;
    }

    private function issueLine(string $id, ?string $state, ?string $type, string $summary): string
    {
        return sprintf('%-7s %-12s %-6s %s', $id, $state ?? '-', $type ?? '-', $summary);
    }

    /**
     * @template TData
     *
     * @param  TData  $data
     * @param  callable(TData): void  $render
     */
    private function output(mixed $data, callable $render): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $render($data);
        }

        return self::SUCCESS;
    }

    private function git(string ...$arguments): ?string
    {
        $process = new Process(['git', ...$arguments], $this->laravel->basePath());
        $process->run();
        $output = trim($process->getOutput());

        return $process->isSuccessful() && $output !== '' ? $output : null;
    }

    private function id(): string
    {
        $id = $this->stringArgument('id');

        if ($id === null) {
            throw new YouTrackException('This action needs an issue id: php artisan agentio:yt '.(string) $this->argument('action').' <ID>');
        }

        return $id;
    }

    private function stringArgument(string $name): ?string
    {
        $value = $this->argument($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function failWith(string $message): int
    {
        $this->components->error($message);

        return self::FAILURE;
    }

    private function help(): void
    {
        $this->line(<<<'HELP'
            Usage: php artisan agentio:yt <action> [<ID>] [options] [--json]
            Works through the YouTrack MCP server with YOUTRACK_URL, YOUTRACK_TOKEN (environment or .env).

              ideas                    Ideas waiting for planning (tag idea or Type Idea, Stage Backlog, not claimed)
              ready-epics              Epics ready to be worked on (full readiness rule) with their first wave of tasks
              claimed-epics            Epics with the agent-claimed tag and the owners of their claims
              tree <EPIC>              Epic -> stories -> tasks with states, dependencies and readiness
              ready-tasks <EPIC>       Tasks of the epic that can be started now
              blocked                  Blocked issues with reasons, Ready tasks and epics waiting for dependencies
              validate <EPIC|IDEA>     Structure checks: prefixes and types, parents, empty stories, first wave, cycles (exit 2)
              claim <ID> [--as=SUFFIX] [--plan=TEXT] [--branch=B] [--worktree=W] [--owner=O]
                                       Claim or resume an own claim: [AGENT:START], tag agent-claimed, Stage In Progress,
                                       re-read, verify. CLAIMED / RESUMED (exit 0) or LOST (exit 3)
              release <ID> --state=S [--comment=TEXT]
                                       Post an optional comment, set Stage, remove the agent-claimed tag
              resumable                Epics and ideas this machine left unfinished (claimed here, no session needed)
              state <ID>               The Stage of the issue
              kb-tree [<ARTICLE>] [--depth=N]
                                       Knowledge base tree with article ids (--depth=1: direct children only)
            HELP);
    }
}
