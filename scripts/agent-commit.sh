#!/usr/bin/env bash
# Commits only the given files, under a repository-wide lock, so that several
# subagents developing tasks in one worktree never pick up each other's changes.
#
# Part of obrazmisli/agentio; run it with:
#   php artisan agentio:commit <TASK-ID> "<message>" <file> [<file>...]
# Deleted files are passed the same way; the message gets the "<TASK-ID>: " prefix.
set -euo pipefail

if [[ -z "${AGENTIO_ROOT:-}" ]]; then
    echo "Run it with: php artisan agentio:commit <TASK-ID> \"<message>\" <file> [<file>...]" >&2
    exit 64
fi

cd "$AGENTIO_ROOT"

if [[ $# -lt 3 ]]; then
    echo "Usage: php artisan agentio:commit <TASK-ID> \"<message>\" <file> [<file>...]" >&2
    exit 64
fi

task="$1"
message="$2"
shift 2

if [[ ! "$task" =~ ^[A-Z][A-Z0-9_]*-[0-9]+$ ]]; then
    echo "Invalid task id: $task" >&2
    exit 64
fi

lock="$(git rev-parse --git-common-dir)/agent-commit.lock"

commit() {
    git add -A -- "$@"
    git commit --quiet -m "$task: $message" -- "$@"
    git log -1 --format='%h %s'
}

if command -v flock >/dev/null; then
    (
        flock -w 120 9 || { echo "Could not acquire $lock" >&2; exit 75; }
        commit "$@"
    ) 9>"$lock"
    exit $?
fi

# No flock (e.g. macOS): an atomic mkdir lock, waiting up to 120 seconds.
for (( waited = 0; waited < 240; waited++ )); do
    mkdir "$lock.d" 2>/dev/null && break
    (( waited == 239 )) && { echo "Could not acquire $lock.d" >&2; exit 75; }
    sleep 0.5
done
trap 'rmdir "$lock.d" 2>/dev/null' EXIT
commit "$@"
