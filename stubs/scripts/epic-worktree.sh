#!/usr/bin/env bash
# Creates (or reuses) the git worktree of an epic and prepares an isolated environment in it:
# composer dependencies, its own .env (own APP_KEY, SQLite database, cache/queue prefixes, port),
# migrations and, when the project has a frontend build, JS dependencies and the build.
# Prints the worktree path on the last line of stdout.
#
# Usage: scripts/epic-worktree.sh <EPIC-ID>            create or reuse, then prepare
#        scripts/epic-worktree.sh <EPIC-ID> --remove   remove the worktree (the branch is kept)
#
# Env: BASE_BRANCH ({{base_branch}}), WORKTREES_DIR (<repo>/../worktrees), YOUTRACK_URL/YOUTRACK_TOKEN (for the slug),
#      WORKTREE_SQLITE=1 (0 keeps the database settings of .env.example),
#      WORKTREE_PORT_BASE=8100 (APP_URL=http://localhost:<base + epic number % 800>).
# Project-specific steps: an executable scripts/epic-worktree.local.sh runs last inside the worktree
# with the epic id as its argument (seeders, extra services, ...).
set -euo pipefail

epic="${1:?Usage: $0 <EPIC-ID> [--remove]}"
action="${2:-create}"

if [[ ! "$epic" =~ ^[A-Z][A-Z0-9_]*-[0-9]+$ ]]; then
    echo "Invalid epic id: $epic" >&2
    exit 64
fi

root="$(cd "$(git -C "$(dirname "${BASH_SOURCE[0]}")" rev-parse --path-format=absolute --git-common-dir)/.." && pwd -P)"
base="${BASE_BRANCH:-{{base_branch}}}"
mkdir -p "${WORKTREES_DIR:-$root/../worktrees}"
worktrees="$(cd "${WORKTREES_DIR:-$root/../worktrees}" && pwd -P)"
dir="$worktrees/$epic"
log() { echo "[epic-worktree $epic] $*" >&2; }

if [[ "$action" == "--remove" ]]; then
    if [[ -d "$dir" ]]; then
        if [[ -n "$(git -C "$dir" status --porcelain)" ]]; then
            log "worktree $dir has uncommitted changes, not removing"
            exit 1
        fi
        git -C "$root" worktree remove --force "$dir"
        log "removed $dir (branch kept)"
    fi
    exit 0
fi

branch="$(git -C "$root" for-each-ref --format='%(refname:short)' "refs/heads/epic/$epic-*" | head -n 1)"

if [[ -z "$branch" ]]; then
    slug="$(php "$root/scripts/yt.php" slug "$epic")"
    branch="epic/$epic-$slug"
fi

mkdir -p "$worktrees"

if [[ -d "$dir/.git" || -f "$dir/.git" ]]; then
    log "reusing $dir ($(git -C "$dir" branch --show-current))"
elif git -C "$root" show-ref --verify --quiet "refs/heads/$branch"; then
    git -C "$root" worktree add "$dir" "$branch" >&2
else
    git -C "$root" worktree add -b "$branch" "$dir" "$base" >&2
fi

# The JS package manager follows the lock file; without one the frontend steps are skipped.
js_manager=""
if [[ -f "$dir/bun.lock" || -f "$dir/bun.lockb" ]]; then
    js_manager="bun"
elif [[ -f "$dir/package-lock.json" ]]; then
    js_manager="npm"
fi

ready_marker="$(git -C "$dir" rev-parse --path-format=absolute --git-dir)/agent-env-ready"
sha1() { if command -v sha1sum >/dev/null; then sha1sum; else shasum -a 1; fi; }
lock_files_hash() { { cat "$dir/composer.lock" "$dir/bun.lock" "$dir/bun.lockb" "$dir/package-lock.json" 2>/dev/null || true; } | sha1 | cut -d' ' -f1; }
lock_hash="$(lock_files_hash)"

if [[ -f "$ready_marker" && "$(cat "$ready_marker")" == "$lock_hash" ]]; then
    log "environment already prepared"
    echo "$dir"
    exit 0
fi

cd "$dir"
log "preparing environment in $dir"

if [[ ! -f .env && -f .env.example ]]; then
    number="${epic##*-}"
    prefix="$(echo "$epic" | tr '[:upper:]-' '[:lower:]_')"
    settings=(
        APP_URL "http://localhost:$(( ${WORKTREE_PORT_BASE:-8100} + number % 800 ))"
        REDIS_PREFIX "${prefix}_database_"
        CACHE_PREFIX "${prefix}_cache_"
        HORIZON_PREFIX "${prefix}_horizon:"
        SESSION_COOKIE "${prefix}_session"
    )
    if [[ "${WORKTREE_SQLITE:-1}" == "1" ]]; then
        settings+=(DB_CONNECTION sqlite DB_DATABASE "$dir/database/database.sqlite")
        mkdir -p database
        touch database/database.sqlite
    fi
    cp .env.example .env
    php -r '
        [$file, $values] = [$argv[1], array_slice($argv, 2)];
        $env = file_get_contents($file);
        foreach (array_chunk($values, 2) as [$key, $value]) {
            $line = $key."=".$value;
            $env = preg_match("/^#?\s*".preg_quote($key, "/")."=.*$/m", $env)
                ? preg_replace("/^#?\s*".preg_quote($key, "/")."=.*$/m", $line, $env, 1)
                : rtrim($env).PHP_EOL.$line.PHP_EOL;
        }
        file_put_contents($file, $env);
    ' .env "${settings[@]}"
fi

composer install --no-interaction --prefer-dist --no-progress >&2

if [[ -f artisan ]]; then
    if [[ -f .env ]] && ! grep -qE '^APP_KEY=.+' .env; then
        php artisan key:generate --no-interaction --force >&2
    fi
    php artisan migrate --force --no-interaction >&2
    php artisan storage:link --no-interaction >&2 || true
fi

has_build_script() {
    [[ -f package.json ]] && php -r '$p = json_decode((string) file_get_contents("package.json"), true); exit(isset($p["scripts"]["build"]) ? 0 : 1);'
}

if [[ -z "$js_manager" ]]; then
    log "no bun.lock or package-lock.json: frontend steps skipped"
elif ! command -v "$js_manager" >/dev/null; then
    log "$js_manager is not installed: frontend steps skipped"
else
    case "$js_manager" in
        bun) bun install --frozen-lockfile >&2 || bun install >&2 ;;
        npm) npm ci >&2 || npm install >&2 ;;
    esac
    if has_build_script; then
        for attempt in 1 2 3; do
            "$js_manager" run build >&2 && break
            (( attempt == 3 )) && { log "frontend build failed 3 times (check the network if it downloads assets)"; exit 1; }
            log "frontend build failed (attempt $attempt), retrying"
            sleep 10
        done
    else
        log "package.json has no build script: frontend build skipped"
    fi
fi

if [[ -x "$dir/scripts/epic-worktree.local.sh" ]]; then
    log "running scripts/epic-worktree.local.sh"
    "$dir/scripts/epic-worktree.local.sh" "$epic" >&2
fi

# Installing may create lock files (e.g. composer.lock that is not committed): hash the final state.
lock_files_hash > "$ready_marker"
log "environment ready"
echo "$dir"
