#!/usr/bin/env bash
# Runs the tests writing output to a log file, then kills Playwright servers left behind by
# browser tests in this checkout. Those orphans keep stdout open, so piping `<tests> | tail`
# would otherwise hang forever. The commands are configurable:
#
#   AGENTIO_TEST_COMMAND       narrow run, gets the script arguments  (default: php artisan test --compact)
#   AGENTIO_FULL_TEST_COMMAND  full quality gate                      (default: composer test when composer.json
#                                                                       has a "test" script, else the narrow command)
#
# Usage: scripts/run-tests.sh [test args...]      e.g. scripts/run-tests.sh --filter=Avatar
#        RUN_TESTS_FULL=1 scripts/run-tests.sh    full quality gate
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LOG_DIR="$ROOT/storage/logs/tests"
LOG="$LOG_DIR/$(date +%Y%m%d-%H%M%S)-$$.log"
mkdir -p "$LOG_DIR"
cd "$ROOT" || exit 1
# scripts/php/*.ini: test-only PHP settings (zend.assertions=1, so assert() lines run and count in coverage).
export PHP_INI_SCAN_DIR=":$ROOT/scripts/php"

cleanup_playwright() {
    local pid
    for pid in $(pgrep -f '^node .*playwright run-server' 2>/dev/null); do
        if [[ "$(readlink -f "/proc/$pid/cwd" 2>/dev/null)" == "$ROOT" ]]; then
            kill "$pid" 2>/dev/null
        fi
    done
}
trap cleanup_playwright EXIT

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
    ( eval "$full" ) >"$LOG" 2>&1 </dev/null
else
    ( eval "$narrow \"\$@\"" ) >"$LOG" 2>&1 </dev/null
fi
status=$?

grep -E '^\{"tool"' "$LOG" | cut -c1-2000 || tail -n 40 "$LOG"
echo "exit=$status log=$LOG"
exit "$status"
