<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Obrazmisli\Agentio\Process\AgentComment;
use Obrazmisli\Agentio\Process\AgentCommentKind;
use Obrazmisli\Agentio\Process\AgentComments;
use Obrazmisli\Agentio\YouTrack\Comment;

function comment(string $text, string $issueId = 'TP-2'): Comment
{
    return new Comment('7-'.crc32($text), $issueId, $text, 'agent', null, CarbonImmutable::parse('2026-10-03 07:00:00'));
}

function start(string $owner): string
{
    return "[AGENT:START]\nowner: `{$owner}`\nbranch: `epic/TP-2-avatar`\nworktree: `/srv/worktrees/TP-2`\n\nPlan follows.";
}

it('parses an agent comment', function () {
    $comment = AgentComment::fromComment(comment("  \n".start('host:/srv/worktrees/TP-2')));

    expect($comment)->not->toBeNull()
        ->and($comment?->kind)->toBe('START')
        ->and($comment?->kind())->toBe(AgentCommentKind::Start)
        ->and($comment?->is(AgentCommentKind::Start))->toBeTrue()
        ->and($comment?->issueId)->toBe('TP-2')
        ->and($comment?->author)->toBe('agent')
        ->and($comment?->owner())->toBe('host:/srv/worktrees/TP-2')
        ->and($comment?->field('branch'))->toBe('epic/TP-2-avatar')
        ->and($comment?->field('Worktree'))->toBe('/srv/worktrees/TP-2')
        ->and($comment?->field('missing'))->toBeNull()
        ->and($comment?->body())->toStartWith('owner: `host:/srv/worktrees/TP-2`')
        ->and($comment?->endsClaim())->toBeFalse()
        ->and($comment?->toArray())->toMatchArray(['kind' => 'START', 'owner' => 'host:/srv/worktrees/TP-2', 'createdAt' => '2026-10-03T07:00:00+00:00']);
});

it('ignores comments that are not agent comments', function (string $text) {
    expect(AgentComment::fromComment(comment($text)))->toBeNull()
        ->and(AgentComment::isAgentComment($text))->toBeFalse();
})->with([
    'plain text' => ['Looks good to me'],
    'marker inside the text' => ['See [AGENT:DONE] above'],
    'lowercase marker' => ['[agent:done] finished'],
]);

it('keeps unknown kinds', function () {
    $comment = AgentComment::fromComment(comment('[AGENT:REVIEW] Approved'));

    expect($comment?->kind)->toBe('REVIEW')
        ->and($comment?->kind())->toBeNull()
        ->and($comment?->endsClaim())->toBeFalse()
        ->and($comment?->body())->toBe('Approved')
        ->and(AgentComment::isAgentComment('[AGENT:REVIEW] Approved'))->toBeTrue();
});

it('reads the owner only from START comments', function () {
    expect(AgentComment::fromComment(comment("[AGENT:DONE]\nowner: `host:/x`"))?->owner())->toBeNull()
        ->and(AgentComment::fromComment(comment("[AGENT:START]\nowner: host:/x#TP-6 (resumed)"))?->owner())->toBe('host:/x#TP-6')
        ->and(AgentComment::fromComment(comment("[AGENT:START]\nowner: ``"))?->owner())->toBeNull()
        ->and(AgentComment::fromComment(comment("[AGENT:START]\nPlan only"))?->owner())->toBeNull();
});

it('knows which kinds end a claim', function (AgentCommentKind $kind, bool $endsClaim) {
    expect($kind->endsClaim())->toBe($endsClaim);
})->with([
    [AgentCommentKind::Start, false],
    [AgentCommentKind::Decision, false],
    [AgentCommentKind::Blocked, true],
    [AgentCommentKind::Done, true],
    [AgentCommentKind::Release, true],
]);

it('finds the owner of the active claim like scripts/yt.php', function (array $texts, ?string $owner) {
    $comments = AgentComments::fromComments(array_map(comment(...), $texts));

    expect($comments->claimOwner())->toBe($owner)
        ->and($comments->activeClaim()?->owner())->toBe($owner);
})->with([
    'no comments' => [[], null],
    'claimed' => [[start('a:/w')], 'a:/w'],
    'the earliest start wins' => [[start('a:/w'), start('b:/w')], 'a:/w'],
    'a start without owner (plan) does not claim' => [["[AGENT:START]\nPlan", start('b:/w')], 'b:/w'],
    'done ends the claim' => [[start('a:/w'), '[AGENT:DONE] ok'], null],
    'blocked ends the claim' => [[start('a:/w'), '[AGENT:BLOCKED] help'], null],
    'release ends the claim' => [[start('a:/w'), '[AGENT:RELEASE] stop'], null],
    'a new claim after release' => [[start('a:/w'), '[AGENT:RELEASE]', start('b:/w'), '[AGENT:DECISION] x'], 'b:/w'],
]);

it('filters and finds agent comments', function () {
    $comments = AgentComments::fromComments([
        comment(start('a:/w')),
        comment('Human note'),
        comment('[AGENT:BLOCKED] first'),
        comment('[AGENT:DECISION] choose'),
        comment('[AGENT:BLOCKED] second'),
    ]);

    expect($comments)->toHaveCount(4)
        ->and($comments->all())->toHaveCount(4)
        ->and($comments->ofKind(AgentCommentKind::Blocked))->toHaveCount(2)
        ->and($comments->last()?->kind)->toBe('BLOCKED')
        ->and($comments->last(AgentCommentKind::Blocked)?->body())->toBe('second')
        ->and($comments->last(AgentCommentKind::Done))->toBeNull()
        ->and(iterator_to_array($comments))->toHaveCount(4)
        ->and((new AgentComments)->last())->toBeNull();
});
