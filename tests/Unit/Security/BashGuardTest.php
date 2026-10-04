<?php

declare(strict_types=1);

use Obrazmisli\Agentio\Security\BashGuard;

function guard(): BashGuard
{
    return new BashGuard('/srv/app', ['develop', 'main', 'master'], ['/srv/app', '/srv/shared']);
}

it('refuses access to the YouTrack token and to secrets', function (string $command) {
    expect(guard()->reason($command, '/srv/app'))->not->toBeNull($command);
})->with([
    'cat .env',
    'grep TOKEN ./.env',
    'head -5 /srv/app/.env',
    'cat .claude/settings.local.json',
    'php artisan config:show agentio',
    'php artisan config:show agentio.youtrack.token',
    'php -r \'echo config("agentio.youtrack.token");\'',
    'echo $YOUTRACK_TOKEN',
    'printenv',
    'env',
    'cat /proc/self/environ',
    'cat ~/.claude.json',
    'cat .env.production',
    'sudo rm -rf /',
]);

it('allows the ordinary commands of the agents', function (string $command) {
    expect(guard()->reason($command, '/srv/app'))->toBeNull($command);
})->with([
    'cat .env.example',
    'git diff -- .env.example',
    'php artisan config:show app.url',
    'php artisan agentio:yt claim XY-1 --as=XY-1',
    'php artisan agentio:test --filter=Profile',
    'php artisan agentio:commit XY-1 "Add it" app/Models/Note.php',
    'php artisan agentio:run --dry-run',
    'ls -la .envrc',
    'git push -u origin epic/XY-2-notes',
    'git branch -D epic/XY-2-notes',
    'rm -rf storage/framework/cache/data',
    'rm /srv/shared/tmp.txt',
    'find . -name "*.php"',
]);

it('protects branches and history', function (string $command, string $reason) {
    expect(guard()->reason($command, '/srv/app'))->toContain($reason);
})->with([
    ['git push origin develop', "pushing to protected branch 'develop'"],
    ['git push origin HEAD:main', "pushing to protected branch 'main'"],
    ['git push --force origin epic/XY-2', 'force/mirror push (--force)'],
    ['git push origin feature/x', "only the branches of issues (e.g. TP-12) may be pushed or deleted (got 'feature/x')"],
    ['git branch -D develop', "deleting, renaming or force-moving branch 'develop'"],
    ['git reset --hard HEAD~1', 'git reset --hard is not allowed'],
    ['git stash', 'git stash is not allowed'],
    ['git checkout .', 'git checkout of the whole tree is not allowed'],
    ['git switch develop', "switching to 'develop'"],
    ['git rebase main', 'history rewriting (git rebase)'],
    ['git worktree remove ../worktrees/XY-2', 'worktrees are managed by the agent loop'],
    ['git -C /etc status', 'git -C outside of the project directory'],
]);

it('keeps destructive commands inside the project', function (string $command, string $reason) {
    expect(guard()->reason($command, '/srv/app'))->toContain($reason);
})->with([
    ['rm -rf /srv/app', "rm on '/srv/app' outside of the project directory"],
    ['rm -rf ../other', "rm on '../other' outside of the project directory"],
    ['mv app /tmp/app', "mv on '/tmp/app' outside of the project directory"],
    ['find / -delete', "find on '/' outside of the project directory"],
    ['rm -rf .git/hooks', 'rm inside .git is not allowed'],
]);

it('keeps the commands of humans and of the loop away from the agents', function (string $command) {
    expect(guard()->reason($command, '/srv/app'))->toContain('run by humans');
})->with([
    'php artisan agentio:install',
    'php artisan agentio:setup-youtrack --dry-run',
    'php artisan agentio:worktree XY-2 --remove',
    'php artisan agentio:run --once',
    'php artisan tinker',
]);
