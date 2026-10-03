<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\YouTrack;

use Obrazmisli\Agentio\Process\AgentComment;
use Obrazmisli\Agentio\Process\AgentComments;
use Obrazmisli\Agentio\Process\ReadinessGraph;

/**
 * Read access to the issues of the configured YouTrack project as Issue / Comment objects.
 */
final readonly class IssueRepository
{
    public function __construct(private Client $client, private string $project) {}

    /**
     * The short name of the project, e.g. "TP".
     */
    public function project(): string
    {
        return $this->project;
    }

    public function client(): Client
    {
        return $this->client;
    }

    /**
     * Issues matching a raw YouTrack query (not limited to the project).
     *
     * @return list<Issue>
     *
     * @throws YouTrackException
     */
    public function search(string $query): array
    {
        return array_map(Issue::fromApi(...), $this->client->searchIssues($query));
    }

    /**
     * Issues of the project, optionally narrowed by an extra query, e.g. "Type: Epic".
     *
     * @return list<Issue>
     *
     * @throws YouTrackException
     */
    public function projectIssues(string $filter = ''): array
    {
        return $this->search(trim('project: '.$this->project.' '.$filter));
    }

    /**
     * A single issue with its description.
     *
     * @throws YouTrackException
     */
    public function find(string $id): Issue
    {
        return Issue::fromApi($this->client->issue($id));
    }

    /**
     * The readiness graph of the whole project; issues outside the project are loaded on demand.
     *
     * @throws YouTrackException
     */
    public function graph(): ReadinessGraph
    {
        return new ReadinessGraph($this->projectIssues(), function (string $id): ?Issue {
            try {
                return Issue::fromApi($this->client->issue($id, Client::ISSUE_FIELDS));
            } catch (YouTrackException $exception) {
                if ($exception->isNotFound()) {
                    return null;
                }

                throw $exception;
            }
        });
    }

    /**
     * Ideas waiting for planning: Type Idea or tag "idea", State Backlog, not claimed.
     *
     * @return list<Issue>
     *
     * @throws YouTrackException
     */
    public function ideas(): array
    {
        // "State: Backlog" also matches a Stage named Backlog, so State is re-checked here.
        return array_values(array_filter(
            $this->projectIssues('State: Backlog'),
            fn (Issue $issue): bool => $issue->isIdea() && $issue->hasState(State::Backlog) && ! $issue->isClaimed(),
        ));
    }

    /**
     * Epics with the agent-claimed tag.
     *
     * @return list<Issue>
     *
     * @throws YouTrackException
     */
    public function claimedEpics(): array
    {
        return array_values(array_filter(
            $this->projectIssues('Type: Epic tag: '.Tag::Claimed->value),
            fn (Issue $issue): bool => $issue->hasType(IssueType::Epic) && $issue->isClaimed(),
        ));
    }

    /**
     * Comments of an issue, oldest first.
     *
     * @return list<Comment>
     *
     * @throws YouTrackException
     */
    public function comments(string $id): array
    {
        return array_map(fn (array $raw): Comment => Comment::fromApi($raw, $id), $this->client->comments($id));
    }

    /**
     * The [AGENT:*] comments of an issue, oldest first.
     *
     * @throws YouTrackException
     */
    public function agentComments(string $id): AgentComments
    {
        return AgentComments::fromComments($this->comments($id));
    }

    /**
     * The latest comments across the project (or of the issues matching $issueQuery), newest first.
     *
     * @return list<Comment>
     *
     * @throws YouTrackException
     */
    public function recentComments(int $limit = 50, ?string $issueQuery = null): array
    {
        $comments = [];

        foreach ($this->client->commentActivities($issueQuery ?? 'project: '.$this->project, $limit) as $activity) {
            foreach (is_array($activity['added'] ?? null) ? $activity['added'] : [] as $added) {
                if (is_array($added)) {
                    $comments[] = Comment::fromApi([...$added, 'author' => $activity['author'] ?? null]);
                }
            }
        }

        return $comments;
    }

    /**
     * The latest [AGENT:*] comments across the project (or of the issues matching $issueQuery), newest first.
     *
     * @return list<AgentComment>
     *
     * @throws YouTrackException
     */
    public function recentAgentComments(int $limit = 50, ?string $issueQuery = null): array
    {
        return array_values(array_filter(array_map(AgentComment::fromComment(...), $this->recentComments($limit, $issueQuery))));
    }

    /**
     * The latest [AGENT:*] comments of the given issues, newest first.
     *
     * @param  list<string>  $ids
     * @return list<AgentComment>
     *
     * @throws YouTrackException
     */
    public function recentAgentCommentsOf(array $ids, int $limit = 50): array
    {
        return $ids === [] ? [] : $this->recentAgentComments($limit, 'issue ID: '.implode(', ', $ids));
    }

    /**
     * Link to the issue in the YouTrack web UI.
     */
    public function url(string $id): string
    {
        return $this->client->issueUrl($id);
    }
}
