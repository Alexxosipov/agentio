<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Dashboard;

use Obrazmisli\Agentio\Process\AgentComment;
use Obrazmisli\Agentio\Process\AgentCommentKind;
use Obrazmisli\Agentio\Review\EpicAcceptance;
use Obrazmisli\Agentio\Review\EpicBranch;
use Obrazmisli\Agentio\Settings;
use Obrazmisli\Agentio\YouTrack\Issue;
use Obrazmisli\Agentio\YouTrack\IssueType;
use Obrazmisli\Agentio\YouTrack\YouTrackException;

/**
 * The acceptance view of an epic: what its branch changes (commits, files), whether it can be merged into the
 * base branch now, the orchestrator's final [AGENT:DONE] and the review verdict of every story.
 */
final readonly class ReviewPresenter
{
    public function __construct(
        private YouTrackSource $source,
        private Settings $settings,
        private EpicAcceptance $acceptance,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function present(string $id): array
    {
        $epic = $this->source->attempt(fn (): ?Issue => $this->source->graph()->find($id), null);
        $branch = EpicBranch::find($this->settings, $id);
        $checks = $branch === null ? [] : $this->acceptance->checks($epic, $branch);
        $merged = $branch?->isMerged() ?? false;

        return [
            'epicId' => $id,
            'base' => $this->settings->baseBranch(),
            'mergePolicy' => $this->settings->mergePolicy()->value,
            'actions' => (bool) config('agentio.ui.actions', true),
            'branch' => $branch === null ? null : $this->branch($branch, $merged),
            'checks' => $checks,
            'canAccept' => $branch !== null && $epic !== null && array_filter($checks, fn (array $check): bool => $check['ok'] === false) === [],
            'summary' => $epic === null ? null : $this->source->attempt(fn (): ?array => $this->summary($epic), null),
            'stories' => $epic === null ? [] : $this->source->attempt(fn (): array => $this->stories($epic), []),
            'youtrack' => $this->source->health(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function branch(EpicBranch $branch, bool $merged): array
    {
        $files = $branch->files();
        $worktree = $branch->worktreeGit();

        return [
            'name' => $branch->name,
            'head' => $branch->head(),
            'merged' => $merged,
            ...$branch->divergence(),
            'commits' => $branch->commits(),
            'files' => $files,
            'added' => array_sum(array_map(fn (array $file): int => $file['added'] ?? 0, $files)),
            'deleted' => array_sum(array_map(fn (array $file): int => $file['deleted'] ?? 0, $files)),
            'worktree' => $branch->worktree === null ? null : [
                'path' => $branch->worktree,
                'exists' => $worktree !== null,
                'url' => $worktree === null ? null : self::appUrl($branch->worktree),
            ],
        ];
    }

    /**
     * The orchestrator's final report: the latest [AGENT:DONE] of the epic.
     *
     * @return array{body: string, createdAt: string|null}|null
     *
     * @throws YouTrackException
     */
    private function summary(Issue $epic): ?array
    {
        $done = $this->source->agentComments($epic->id)->last(AgentCommentKind::Done);

        return $done === null ? null : ['body' => $done->body(), 'createdAt' => $done->createdAt?->toIso8601String()];
    }

    /**
     * The stories of the epic with the verdict of their latest review.
     *
     * @return list<array<string, mixed>>
     *
     * @throws YouTrackException
     */
    private function stories(Issue $epic): array
    {
        $graph = $this->source->graph();
        $stories = [];

        foreach ($graph->children($epic->id) as $id) {
            $story = $graph->find($id);

            if ($story === null || ! $story->hasType(IssueType::Story)) {
                continue;
            }

            $verdict = null;

            foreach ($this->source->agentComments($id) as $comment) {
                $verdict = self::verdict($comment) ?? $verdict;
            }

            $stories[] = [
                ...$this->source->card($story),
                'verdict' => $verdict === null ? null : $verdict[0],
                'verdictAt' => $verdict === null ? null : $verdict[1]->createdAt?->toIso8601String(),
            ];
        }

        return $stories;
    }

    /**
     * The review verdict a comment of the reviewer carries (APPROVED in [AGENT:DONE], CHANGES REQUESTED in
     * [AGENT:DECISION], or a block), with the comment.
     *
     * @return array{0: string, 1: AgentComment}|null
     */
    private static function verdict(AgentComment $comment): ?array
    {
        return match (true) {
            $comment->is(AgentCommentKind::Done) && preg_match('/\bAPPROVED\b/', $comment->body()) === 1 => ['APPROVED', $comment],
            $comment->is(AgentCommentKind::Decision) && preg_match('/\bCHANGES[ _]REQUESTED\b/', $comment->body()) === 1 => ['CHANGES_REQUESTED', $comment],
            $comment->is(AgentCommentKind::Blocked) => ['BLOCKED', $comment],
            default => null,
        };
    }

    /**
     * APP_URL of the worktree's own .env (the port agentio:worktree gave the epic), when it has one.
     */
    private static function appUrl(string $worktree): ?string
    {
        $env = @file_get_contents($worktree.'/.env');

        if ($env === false || preg_match('/^APP_URL=["\']?(https?:\/\/[^\s"\']+)/m', $env, $match) !== 1) {
            return null;
        }

        return $match[1];
    }
}
