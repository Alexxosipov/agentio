#!/usr/bin/env bash
# Runs the tests writing output to a log file, in a process group of their own, then kills what the run
# left behind in that group (Playwright servers of browser tests: those orphans keep stdout open, so piping
# `<tests> | tail` would otherwise hang forever) — and only that: parallel runs in the same worktree keep
# their servers. The commands are configurable:
#
#   AGENTIO_TEST_COMMAND       narrow run, gets the script arguments  (default: php artisan test --compact)
#   AGENTIO_FULL_TEST_COMMAND  full quality gate                      (default: composer test when composer.json
#                                                                       has a "test" script, else the narrow command)
#
# Part of obrazmisli/agentio; run it with:
#   php artisan agentio:test [test args...]      e.g. php artisan agentio:test --filter=Avatar
#   php artisan agentio:test --full              full quality gate (RUN_TESTS_FULL=1)
set -uo pipefail

if [[ -z "${AGENTIO_ROOT:-}" ]]; then
    echo "Run it with: php artisan agentio:test [test args...] (or --full)" >&2
    exit 64
fi

ROOT="$(cd "$AGENTIO_ROOT" && pwd)"
LOG_DIR="$ROOT/storage/logs/tests"
LOG="$LOG_DIR/$(date +%Y%m%d-%H%M%S)-$$.log"
mkdir -p "$LOG_DIR"
cd "$ROOT" || exit 1
# php/*.ini next to this script: test-only PHP settings (zend.assertions=1, so assert() lines run and count in coverage).
export PHP_INI_SCAN_DIR=":$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/php"

# Run a command line in a new process group (when setsid is available) and kill what is left of the group.
run_isolated() {
    local command="$1" pid status
    shift
    if ! command -v setsid >/dev/null; then
        ( eval "$command \"\$@\"" ) >"$LOG" 2>&1 </dev/null
        return $?
    fi
    setsid bash -c "$command \"\$@\"" agentio-test "$@" >"$LOG" 2>&1 </dev/null &
    pid=$!
    wait "$pid"
    status=$?
    kill -TERM -- "-$pid" 2>/dev/null
    return "$status"
}

narrow="${AGENTIO_TEST_COMMAND:-php artisan test --compact}"

if [[ "${RUN_TESTS_FULL:-0}" == "1" ]]; then
    full="${AGENTIO_FULL_TEST_COMMAND:-}"
    if [[ -z "$full" ]]; then
        if [[ -f composer.json ]] && php -r '$c = json_decode((string) file_get_contents("composer.json"), true); exit(isset($c["scripts"]["test"]) ? 0 : 1);'; then
            full="composer test"
        else
            full="$narrow"
        fi
    fi
    run_isolated "$full"
else
    run_isolated "$narrow" "$@"
fi
status=$?

grep -E '^\{"tool"' "$LOG" | cut -c1-2000 || tail -n 40 "$LOG"
echo "exit=$status log=$LOG"
exit "$status"
