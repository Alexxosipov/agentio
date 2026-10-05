<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Telegram;

use Closure;
use Obrazmisli\Agentio\Process\AgentComment;
use Obrazmisli\Agentio\Process\AgentCommentKind;
use Obrazmisli\Agentio\Settings;
use Obrazmisli\Agentio\YouTrack\Client;
use Obrazmisli\Agentio\YouTrack\Comment;
use Obrazmisli\Agentio\YouTrack\IssueRepository;
use Obrazmisli\Agentio\YouTrack\YouTrackException;

/**
 * Finds the questions the agents asked since the last look (new [AGENT:BLOCKED] comments of the project, from
 * the comment activities of the YouTrack REST API, like the dashboard) and hands each to $send. The first look
 * only remembers where it is: questions asked before the bot was started are not sent.
 */
final readonly class QuestionWatcher
{
    /** How many recent comment activities a look reads. */
    public const int LIMIT = 50;

    public function __construct(
        private Client $client,
        private Conversation $conversation,
        private Settings $settings,
    ) {}

    /**
     * @param  Closure(Comment, string): mixed  $send  The comment and its message
     * @return int How many questions were handed over
     *
     * @throws YouTrackException
     */
    public function poll(Closure $send): int
    {
        if (! $this->client->isConfigured()) {
            return 0;
        }

        $watermark = $this->conversation->watermark();
        $comments = IssueRepository::commentsFromActivities($this->client->commentActivities('project: '.$this->settings->project(), self::LIMIT));
        $newest = $watermark ?? (int) floor(microtime(true) * 1000);
        $questions = [];

        foreach ($comments as $comment) {
            $created = $comment->createdAt?->getTimestampMs();

            if ($created === null) {
                continue;
            }

            $newest = max($newest, $created);

            if ($watermark !== null && $created > $watermark && AgentComment::fromComment($comment)?->is(AgentCommentKind::Blocked) === true) {
                $questions[] = $comment;
            }
        }

        usort($questions, fn (Comment $left, Comment $right): int => $left->createdAt <=> $right->createdAt);

        foreach ($questions as $question) {
            $send($question, Reports::question($question));
        }

        $this->conversation->setWatermark($newest);

        return count($questions);
    }
}
