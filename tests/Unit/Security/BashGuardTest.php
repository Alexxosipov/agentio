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
    'cat .en?',
    'cat .claude/settings.local.json',
    'php artisan config:show agentio',
    'php artisan config:show agentio.youtrack.token',
    'php -r \'echo config("agentio.youtrack.token");\'',
    'php -r \'echo getenv("X");\'',
    'php -i',
    'php -i | grep TOKEN',
    'echo $YOUTRACK_TOKEN',
    'printenv',
    'env',
    'cat /proc/self/environ',
    'cat /proc/$$/environ',
    'cat ~/.claude.json',
    'cd ~ && rm -rf .ssh',
    'cat .env.production',
    'sudo rm -rf /',
    'echo "$(cat .env)"',
    'ls `cat .env`',
]);

it('allows the ordinary commands of the agents', function (string $command) {
    expect(guard()->reason($command, '/srv/app'))->toBeNull($command);
})->with([
    'cat .env.example',
    'git diff -- .env.example',
    'php artisan config:show app.url',
    'php artisan agentio:yt claim XY-1 --as=XY-1',
    'php artisan agentio:yt claim XY-1 --as=XY-1 --plan="1. Добавить ключ в .env.example и .env
- rm -rf /tmp/cache не нужен"',
    'composer test',
    'vendor/bin/pest tests/Feature/ProfileTest.php --filter=Profile',
    'php artisan agentio:commit XY-1 "Add it" app/Models/Note.php',
    'php artisan agentio:run --dry-run',
    'php artisan agentio:run --dry-run --epic=XY-2',
    'ls -la .envrc',
    'grep -rn "getenv(" app',
    'git push -u origin XY-2',
    'git push origin epic/XY-2-notes',
    'git branch -D XY-2',
    'git -C . status',
    'git --no-pager log --oneline -5',
    'git -c core.quotePath=false status',
    'git config --get user.email',
    'git config user.email',
    'git tag',
    'git tag -l "v*"',
    'git add app/Models/Note.php tests/Unit/NoteTest.php',
    'git commit -m "XY-1: Add it" -- app/Models/Note.php',
    'git merge develop',
    'rm -rf storage/framework/cache/data',
    'rm /srv/shared/tmp.txt',
    'find . -name "*.php"',
    'find storage/framework/cache -type f -delete',
    'cp vendor/laravel/framework/stubs/model.stub stubs/model.stub',
    'sed -i "s/old/new/" app/Models/Note.php',
    'sed -n "1,20p" .agentio.json',
    'vendor/bin/pest > storage/logs/pest.txt 2>&1',
    'php artisan test 2>/dev/null',
    'mkdir -p storage/app/tmp && touch storage/app/tmp/x',
]);

it('protects branches and history', function (string $command, string $reason) {
    expect(guard()->reason($command, '/srv/app'))->toContain($reason);
})->with([
    ['git push origin develop', "pushing to protected branch 'develop'"],
    ['git push origin HEAD:main', "pushing to protected branch 'main'"],
    ['git push --force origin XY-2', 'force/mirror push (--force)'],
    ['git push -fu origin XY-2', 'force/mirror push (-f)'],
    ['git push origin feature/x', "only the branches of issues (e.g. TP-12) may be pushed or deleted (got 'feature/x')"],
    ['git branch -D develop', "deleting, renaming or force-moving branch 'develop'"],
    ['git branch -Mf main', "deleting, renaming or force-moving branch 'main'"],
    ['git reset --hard HEAD~1', 'git reset --hard is not allowed'],
    ['git stash', 'git stash is not allowed'],
    ['git checkout .', 'git checkout of the whole tree is not allowed'],
    ['git switch develop', "switching to 'develop'"],
    ['git checkout -b feature/x', 'creating branches is the business of the agent loop'],
    ['git rebase main', 'git rebase is not a git command agents use'],
    ['git filter-branch --all', 'git filter-branch is not a git command agents use'],
    ['git worktree remove ../worktrees/XY-2', 'worktrees are managed by the agent loop'],
    ['git -C /etc status', 'git -C outside of the project directory'],
    ['git -C . p', 'git p is not a git command agents use (git aliases are not allowed)'],
    ['git -c alias.x=!sh x', 'git -c alias.x=!sh is not allowed'],
    ['git config alias.p "!git push origin HEAD:main"', 'changing the git configuration is not allowed'],
    ['git commit --amend --no-edit', 'git commit --amend / --all is not allowed'],
    ['git commit -am "x"', 'git commit --amend / --all is not allowed'],
    ['git add -A', 'git add of the whole tree is not allowed'],
    ['git add .', 'git add of the whole tree is not allowed'],
    ['git tag XY-2', 'creating, moving or deleting tags is not allowed'],
    ['git remote add evil https://example.com/x.git', 'changing remotes is not allowed'],
]);

it('keeps writes inside the project and away from the agents\' own files', function (string $command, string $reason) {
    expect(guard()->reason($command, '/srv/app'))->toContain($reason);
})->with([
    ['rm -rf /srv/app', "rm on '/srv/app' outside of the project directory"],
    ['rm -rf ../other', "rm on '../other' outside of the project directory"],
    ['cd .. && rm -rf other', "rm on 'other' outside of the project directory"],
    ['mv app /tmp/app', "mv on '/tmp/app' outside of the project directory"],
    ['find / -delete', "find -delete on '/' outside of the project directory"],
    ['find . -exec git push origin main \;', 'find -exec and the writing actions of find are not allowed'],
    ['rm -rf .git/hooks', 'rm inside .git is not allowed'],
    ['cp /tmp/settings.json .claude/settings.json', "cp on '.claude/settings.json' is not allowed: agents do not change the agentio settings"],
    ['cp x.json .agentio.json', "cp on '.agentio.json' is not allowed"],
    ['sed -n -i "s/a/b/" .claude/skills/agentio-work-epic/SKILL.md', "sed -i on '.claude/skills/agentio-work-epic/SKILL.md' is not allowed"],
    ['echo "{}" > .claude/settings.json', "redirection on '.claude/settings.json' is not allowed"],
    ['echo x >> /etc/profile', "redirection on '/etc/profile' outside of the project directory"],
    ['tee vendor/autoload.php < x', "tee on 'vendor/autoload.php' is not allowed"],
    ['cp app/x.php /tmp/x.php', "cp on '/tmp/x.php' outside of the project directory"],
]);

it('keeps the commands of humans and of the loop away from the agents', function (string $command) {
    expect(guard()->reason($command, '/srv/app'))->toContain('run by humans');
})->with([
    'php artisan agentio:install',
    'php artisan agentio:setup-youtrack --dry-run',
    'php artisan agentio:worktree XY-2 --remove',
    'php artisan agentio:accept XY-2',
    'php artisan agentio:run --once',
    'php artisan agentio:run --dry-run --kill',
    'php artisan agentio:run --dry-run --stop',
    'php artisan tinker',
    'php artisan -n agentio:install',
    'php artisan -v tinker --execute="echo 1;"',
    'php artisan --env testing tinker',
]);

it('ignores the root and empty directories among the roots', function () {
    $guard = new BashGuard('/srv/app', ['develop'], ['/srv/app', '/', '']);

    expect($guard->reason('rm -rf /home/user', '/srv/app'))->toContain('outside of the project directory');
});
