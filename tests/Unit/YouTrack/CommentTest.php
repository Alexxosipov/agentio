<?php

declare(strict_types=1);

use Obrazmisli\Agentio\YouTrack\Comment;

it('normalises a comment of the get_issue_comments tool of the MCP server', function () {
    $comment = Comment::fromMcp([
        'author' => 'a.osipov',
        'text' => "[AGENT:START]\nowner: `host:/w`",
        'url' => 'https://yt.example.com:443/issue/TP-13#focus=Comments-7-92.0-0',
        'createdAt' => 1791049186376,
    ], 'TP-13');

    expect($comment->toArray())->toBe([
        'id' => '7-92.0-0',
        'issueId' => 'TP-13',
        'issueSummary' => null,
        'text' => "[AGENT:START]\nowner: `host:/w`",
        'author' => 'a.osipov',
        'authorName' => null,
        'createdAt' => '2026-10-03T17:39:46+00:00',
    ])->and(Comment::fromMcp([], 'TP-1')->toArray())->toMatchArray(['id' => '', 'text' => '', 'author' => null, 'createdAt' => null]);
});
