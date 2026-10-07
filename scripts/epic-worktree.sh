#!/usr/bin/env bash
# Creates (or reuses) the git worktree of an epic on its branch (EPIC_BRANCH, named after the epic id and started
# from the development branch BASE_BRANCH — from the one of origin when it is ahead: epics are merged there through
# pull requests) and prepares an isolated environment in it:
# composer dependencies, its own .env (own APP_KEY, SQLite database, cache/queue prefixes, port),
# migrations and, when the project has a frontend build, JS dependencies and the build.
# Prints the worktree path on the last line of stdout.
#
# Part of alexxosipov/agentio; run it with:
#   php artisan agentio:worktree <EPIC-ID>            create or reuse, then prepare
#   php artisan agentio:worktree <EPIC-ID> --remove   remove the worktree (the branch is kept)
#
# Env (set by agentio:worktree): AGENTIO_ROOT (the project), BASE_BRANCH, EPIC_BRANCH, WORKTREES_DIR,
#   WORKTREE_SQLITE=1 (0 keeps the database settings of .env.example),
#   WORKTREE_PORT_BASE=8100 (APP_URL=http://localhost:<base + epic number % 800>),
#   AGENTIO_WORKTREE_SETUP (optional: a shell command run last inside the worktree with the epic id as $1,
#   for project-specific steps such as seeders or extra services).
set -euo pipefail

epic="${1:?Usage: $0 <EPIC-ID> [--remove]}"
action="${2:-create}"

if [[ ! "$epic" =~ ^[A-Z][A-Z0-9_]*-[0-9]+$ ]]; then
    echo "Invalid epic id: $epic" >&2
    exit 64
fi

if [[ -z "${AGENTIO_ROOT:-}" || -z "${WORKTREES_DIR:-}" ]]; then
    echo "Run it with: php artisan agentio:worktree $epic" >&2
    exit 64
fi

# The main checkout, also when AGENTIO_ROOT is an epic worktree itself.
root="$(cd "$(git -C "$AGENTIO_ROOT" rev-parse --path-format=absolute --git-common-dir)/.." && pwd -P)"
base="${BASE_BRANCH:-main}"
mkdir -p "$WORKTREES_DIR"
worktrees="$(cd "$WORKTREES_DIR" && pwd -P)"
dir="$worktrees/$epic"
log() { echo "[epic-worktree $epic] $*" >&2; }
package="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"

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

branch="${EPIC_BRANCH:-$epic}"

if ! git -C "$root" show-ref --verify --quiet "refs/heads/$branch" \
    && ! git -C "$root" show-ref --verify --quiet "refs/heads/$base"; then
    log "the development branch $base does not exist: run php artisan agentio:install (it creates it)"
    exit 1
fi

if [[ -d "$dir/.git" || -f "$dir/.git" ]]; then
    log "reusing $dir ($(git -C "$dir" branch --show-current))"
elif git -C "$root" show-ref --verify --quiet "refs/heads/$branch"; then
    git -C "$root" worktree add "$dir" "$branch" >&2
else
    start="refs/heads/$base"
    if git -C "$root" remote get-url origin >/dev/null 2>&1 \
        && git -C "$root" fetch --quiet origin "+refs/heads/$base:refs/remotes/origin/$base" 2>/dev/null \
        && git -C "$root" merge-base --is-ancestor "refs/heads/$base" "refs/remotes/origin/$base" 2>/dev/null; then
        start="refs/remotes/origin/$base"
    fi
    git -C "$root" worktree add --no-track -b "$branch" "$dir" "$start" >&2
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
    # The values are written by the EnvFile of the package (quoted when needed, e.g. a path with a space).
    php -r '
        foreach ([$argv[1]."/vendor/autoload.php", $argv[1]."/../../autoload.php"] as $autoload) {
            if (is_file($autoload)) { require $autoload; break; }
        }
        $values = [];
        foreach (array_chunk(array_slice($argv, 3), 2) as [$key, $value]) { $values[$key] = $value; }
        file_put_contents($argv[2], (new Obrazmisli\Agentio\Install\EnvFile($argv[2]))->contentWith($values));
    ' "$package" .env "${settings[@]}"
fi

# A lock file that installs a package from a path the worktree does not have (a copy of agentio in
# packages/agentio, replaced by a Composer repository on another branch) makes composer install fail.
missing="$(php -r '
    foreach ([$argv[1]."/vendor/autoload.php", $argv[1]."/../../autoload.php"] as $autoload) {
        if (is_file($autoload)) { require $autoload; break; }
    }
    foreach (Obrazmisli\Agentio\Git\PathPackages::missingIn($argv[2]) as $name => $path) { echo "$name ($path) "; }
' "$package" "$dir")"
if [[ -n "$missing" ]]; then
    log "ERROR: composer.lock of the branch $(git -C "$dir" branch --show-current) installs ${missing}from a path the worktree does not have: commit the composer.json and composer.lock that install it from its repository to $base, then merge $base into the epic branch (git -C $dir merge $base)"
    exit 1
fi

composer install --no-interaction --prefer-dist --no-progress >&2

if [[ -f artisan ]]; then
    if [[ -f .env ]] && ! grep -qE '^APP_KEY=.+' .env; then
        # An APP_KEY inherited from the environment makes key:generate look for that key in .env and give up.
        env -u APP_KEY php artisan key:generate --no-interaction --force >&2
        if ! grep -qE '^APP_KEY=.+' .env; then
            log "could not generate APP_KEY in $dir/.env"
            exit 1
        fi
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

if [[ -n "${AGENTIO_WORKTREE_SETUP:-}" ]]; then
    log "running AGENTIO_WORKTREE_SETUP"
    bash -c "$AGENTIO_WORKTREE_SETUP" agentio-worktree-setup "$epic" >&2
fi

# Installing may create lock files (e.g. composer.lock that is not committed): hash the final state.
lock_files_hash > "$ready_marker"
log "environment ready"
echo "$dir"
