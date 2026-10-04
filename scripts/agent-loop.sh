#!/usr/bin/env bash
# Autonomous development loop of obrazmisli/agentio: plans ideas and works on ready epics with Claude Code
# in headless mode. Part of the package; start it with `php artisan agentio:run`, which passes the settings:
#
#   php artisan agentio:run                 run forever (every AGENT_LOOP_INTERVAL seconds)
#   php artisan agentio:run --once          one pass, then wait for the started epic sessions to finish
#   php artisan agentio:run --dry-run       only show what would be started (changes nothing)
#   php artisan agentio:run --kill          stop running agent sessions (claims stay; the next run resumes them)
#   php artisan agentio:run --stop          the loop exits after its current step (AGENTIO_STOP_FILE)
#
# Options: --interval=SEC  --max-parallel=N  --max-parallel-tasks=N  --epic=<ID> (only this epic)  --no-plan  --no-wait
#
# Env (set by agentio:run): AGENTIO_ROOT (the project), YOUTRACK_URL, YOUTRACK_TOKEN, AGENTIO_PROJECT, BASE_BRANCH,
#   WORKTREES_DIR, MERGE_POLICY, MAX_PARALLEL, MAX_PARALLEL_TASKS, AGENT_LOOP_INTERVAL, CLAUDE_BIN, CLAUDE_MODEL,
#   AGENT_LOG_DIR, AGENTIO_STOP_FILE, AGENTIO_SESSION_SETTINGS and AGENTIO_PLANNING_SETTINGS (settings of the epic
#   and of the planning sessions, JSON), AGENTIO_MCP_CONFIG
#   (MCP configs of headless sessions, space separated), MAX_RESTARTS=3 (crashed epic sessions resumed before Blocked).
# Files in AGENT_LOG_DIR: loop.log, loop.pid (this loop), <EPIC>.pid/.log (epic sessions), plan-<IDEA>.pid/.log.
set -uo pipefail

if [[ -z "${AGENTIO_ROOT:-}" || ! -f "${AGENTIO_ROOT}/artisan" ]]; then
    echo "Start the agent loop with: php artisan agentio:run" >&2
    exit 64
fi

ROOT="$(cd "$AGENTIO_ROOT" && pwd -P)"
LOG_DIR="${AGENT_LOG_DIR:-$ROOT/storage/logs/agents}"
STOP_FILE="${AGENTIO_STOP_FILE:-$LOG_DIR/stop}"
LOOP_PID_FILE="$LOG_DIR/loop.pid"
INTERVAL="${AGENT_LOOP_INTERVAL:-300}"
MAX_PARALLEL="${MAX_PARALLEL:-2}"
MAX_PARALLEL_TASKS="${MAX_PARALLEL_TASKS:-2}"
MAX_RESTARTS="${MAX_RESTARTS:-3}"
BASE_BRANCH="${BASE_BRANCH:-main}"
CLAUDE_BIN="${CLAUDE_BIN:-claude}"
MERGE_POLICY="${MERGE_POLICY:-local-branch}"
read -r -a MCP_CONFIGS <<<"${AGENTIO_MCP_CONFIG:-}"
HOST="$(hostname)"
MODE="loop"
ONLY_EPIC=""
PLAN_IDEAS=1
WAIT=1

for arg in "$@"; do
    case "$arg" in
        --once) MODE="once" ;;
        --dry-run) MODE="dry-run" ;;
        --kill) MODE="kill" ;;
        --interval=*) INTERVAL="${arg#*=}" ;;
        --max-parallel=*) MAX_PARALLEL="${arg#*=}" ;;
        --max-parallel-tasks=*) MAX_PARALLEL_TASKS="${arg#*=}" ;;
        --epic=*) ONLY_EPIC="${arg#*=}" ;;
        --no-plan) PLAN_IDEAS=0 ;;
        --no-wait) WAIT=0 ;;
        *) echo "Unknown option: $arg" >&2; exit 64 ;;
    esac
done

if [[ -z "${WORKTREES_DIR:-}" ]]; then
    echo "WORKTREES_DIR is not set: run php artisan agentio:install and choose where the epic worktrees live" >&2
    exit 1
fi

mkdir -p "$WORKTREES_DIR" "$LOG_DIR"
WORKTREES_DIR="$(cd "$WORKTREES_DIR" && pwd -P)"
export AGENTIO_ROOT BASE_BRANCH WORKTREES_DIR MAX_PARALLEL_TASKS MERGE_POLICY AGENT_LOG_DIR="$LOG_DIR"
cd "$ROOT" || exit 1

log() { echo "[$(date '+%F %T')] $*" | tee -a "$LOG_DIR/loop.log" >&2; }
artisan() { php "$ROOT/artisan" "$@"; }
yt() { artisan agentio:yt "$@"; }
alive() { [[ -n "${1:-}" ]] && kill -0 "$1" 2>/dev/null; }
stopping() { [[ -f "$STOP_FILE" || "${INTERRUPTED:-0}" == 1 ]]; }
ids() { php -r 'foreach (json_decode(stream_get_contents(STDIN), true) ?: [] as $row) { echo $row["id"], PHP_EOL; }'; }

if [[ -z "${YOUTRACK_URL:-}" || -z "${YOUTRACK_TOKEN:-}" ]]; then
    echo "YOUTRACK_URL and YOUTRACK_TOKEN must be set (run php artisan agentio:install)" >&2
    exit 1
fi

for bin in php git composer setsid "$CLAUDE_BIN"; do
    command -v "$bin" >/dev/null || { echo "Required command not found: $bin" >&2; exit 1; }
done

trap 'INTERRUPTED=1; log "interrupted: finishing the current step and exiting (running sessions keep working)"' INT TERM

# Epic sessions: <EPIC>.pid files with a live process (loop.pid and plan-*.pid are not epics).
running_epics() {
    local file name
    for file in "$LOG_DIR"/*.pid; do
        [[ -e "$file" ]] || continue
        name="$(basename "$file" .pid)"
        [[ "$name" == "loop" || "$name" == plan-* ]] && continue
        alive "$(cat "$file")" && echo "$name"
    done
}

running_count() { running_epics | grep -c . ; }

claude_headless() {
    local dir="$1" logfile="$2" prompt="$3" settings="$4"
    (
        cd "$dir" || exit 1
        exec setsid "$CLAUDE_BIN" -p "$prompt" \
            --permission-mode dontAsk \
            --strict-mcp-config --mcp-config "${MCP_CONFIGS[@]}" \
            --settings "$settings" \
            --output-format stream-json --verbose \
            ${CLAUDE_MODEL:+--model "$CLAUDE_MODEL"} \
            </dev/null >>"$logfile" 2>&1
    ) &
    echo $!
}

epic_state() { yt state "$1" 2>/dev/null; }

# The branch of an epic: named after its id (an epic started by an earlier agentio version: epic/<ID>-<slug>).
epic_branch() {
    if git -C "$ROOT" show-ref --verify --quiet "refs/heads/$1"; then
        echo "$1"
    else
        git -C "$ROOT" for-each-ref --format='%(refname:short)' "refs/heads/epic/$1-*" | head -n 1
    fi
}

launch_epic() {
    local epic="$1" dir pid
    if ! git -C "$ROOT" cat-file -e "$BASE_BRANCH:.claude/skills/agentio-work-epic/SKILL.md" 2>/dev/null; then
        log "ERROR: the agentio skills (.claude/skills/agentio-*) are not committed to $BASE_BRANCH; epic worktrees would not have them"
        return 1
    fi
    log "$epic: preparing worktree"
    if ! dir="$(artisan agentio:worktree "$epic" 2>>"$LOG_DIR/$epic.setup.log" | tail -n 1)" || [[ ! -d "$dir" ]]; then
        log "$epic: worktree setup failed, see $LOG_DIR/$epic.setup.log"
        return 1
    fi
    echo "===== $(date '+%F %T') /agentio-work-epic $epic in $dir =====" >>"$LOG_DIR/$epic.log"
    pid="$(claude_headless "$dir" "$LOG_DIR/$epic.log" "/agentio-work-epic $epic" "$AGENTIO_SESSION_SETTINGS")"
    echo "$pid" >"$LOG_DIR/$epic.pid"
    log "$epic: started /agentio-work-epic (pid $pid, worktree $dir, log $LOG_DIR/$epic.log)"
}

finish_epic() {
    local epic="$1" state branch restarts
    state="$(epic_state "$epic")"
    branch="$(epic_branch "$epic")"
    log "$epic: session finished, epic state: ${state:-unknown}"

    case "$state" in
        Review)
            rm -f "$LOG_DIR/$epic.restarts"
            if [[ "$MERGE_POLICY" != "local-branch" ]] && git -C "$ROOT" remote | grep -q . \
                && command -v gh >/dev/null && gh pr view "$branch" --json url >/dev/null 2>&1; then
                artisan agentio:worktree "$epic" --remove && log "$epic: PR exists, worktree removed"
            elif [[ "$MERGE_POLICY" == "auto-merge" ]] && ! git -C "$ROOT" remote | grep -q .; then
                # The same acceptance as the dashboard's: checks, merge (aborted on a conflict), worktree, Done.
                if artisan agentio:accept "$epic" >>"$LOG_DIR/loop.log" 2>&1; then
                    log "$epic: auto-merged $branch into $BASE_BRANCH and closed"
                else
                    log "$epic: auto-merge not possible (see above), left for a human"
                fi
            else
                log "$epic: ready for human review on branch $branch (worktree kept: $WORKTREES_DIR/$epic)"
            fi
            ;;
        "In Progress")
            restarts=$(( $(cat "$LOG_DIR/$epic.restarts" 2>/dev/null || echo 0) + 1 ))
            echo "$restarts" >"$LOG_DIR/$epic.restarts"
            if (( restarts > MAX_RESTARTS )); then
                yt release "$epic" --state=Blocked --comment="[AGENT:BLOCKED]
**Что мешает:** сессия /agentio-work-epic $MAX_RESTARTS раза подряд завершилась, не доведя эпик до Review.
**Что нужно от человека:** посмотреть лог \`$LOG_DIR/$epic.log\` на машине \`$HOST\` (\`php artisan agentio:log $epic\`), устранить причину и вернуть эпик в Ready." >/dev/null
                rm -f "$LOG_DIR/$epic.restarts"
                log "$epic: ended before Review $MAX_RESTARTS times, marked Blocked"
            else
                log "$epic: ended before Review (attempt $restarts/$MAX_RESTARTS), will be resumed"
            fi
            ;;
        *) rm -f "$LOG_DIR/$epic.restarts" ;;
    esac
}

reap() {
    local file name
    for file in "$LOG_DIR"/*.pid; do
        [[ -e "$file" ]] || continue
        name="$(basename "$file" .pid)"
        [[ "$name" == "loop" ]] && continue
        alive "$(cat "$file")" && continue
        rm -f "$file"
        # A planning session left behind by an interrupted loop: nothing to finish, the idea is re-planned.
        [[ "$name" == plan-* ]] && continue
        finish_epic "$name"
    done
}

# Epics claimed by this machine's worktrees whose session is not running: they are resumed.
resumable_epics() {
    local id state owner
    yt claimed-epics --json \
        | php -r 'foreach (json_decode(stream_get_contents(STDIN), true) ?: [] as $e) { echo $e["id"], "\t", $e["state"], "\t", $e["owner"] ?? "-", PHP_EOL; }' \
        | while IFS=$'\t' read -r id state owner; do
            [[ "$state" == "In Progress" && "$owner" == "$HOST:$WORKTREES_DIR/$id" && -d "$WORKTREES_DIR/$id" ]] || continue
            alive "$(cat "$LOG_DIR/$id.pid" 2>/dev/null)" && continue
            echo "$id"
        done
}

ready_epics() { yt ready-epics --json | ids; }

dispatch_epics() {
    local free epic
    free=$(( MAX_PARALLEL - $(running_count) ))
    for epic in $( { resumable_epics; ready_epics; } | awk '!seen[$0]++'); do
        (( free > 0 )) || break
        stopping && break
        [[ -n "$ONLY_EPIC" && "$epic" != "$ONLY_EPIC" ]] && continue
        alive "$(cat "$LOG_DIR/$epic.pid" 2>/dev/null)" && continue
        launch_epic "$epic" && free=$(( free - 1 ))
    done
}

# Ideas are planned one at a time: concurrent analysts would overwrite each other's knowledge base articles.
plan_ideas() {
    local idea logfile pid
    (( PLAN_IDEAS == 1 )) || return 0
    [[ -n "$ONLY_EPIC" ]] && return 0
    for idea in $(yt ideas --json | ids); do
        stopping && break
        logfile="$LOG_DIR/plan-$idea.log"
        echo "===== $(date '+%F %T') /agentio-plan $idea =====" >>"$logfile"
        log "$idea: planning (log $logfile)"
        pid="$(claude_headless "$ROOT" "$logfile" "/agentio-plan $idea" "$AGENTIO_PLANNING_SETTINGS")"
        echo "$pid" >"$LOG_DIR/plan-$idea.pid"
        while alive "$pid"; do sleep 5; done
        rm -f "$LOG_DIR/plan-$idea.pid"
        log "$idea: planning finished"
    done
}

dry_run() {
    local epic
    echo "== Agent loop dry run ($(date '+%F %T')) =="
    echo "PROJECT=${AGENTIO_PROJECT:-?} MERGE_POLICY=$MERGE_POLICY MAX_PARALLEL=$MAX_PARALLEL MAX_PARALLEL_TASKS=$MAX_PARALLEL_TASKS BASE_BRANCH=$BASE_BRANCH WORKTREES_DIR=$WORKTREES_DIR"
    echo "Running sessions: $(running_epics | tr '\n' ' ')"
    echo
    echo "Ideas that would be planned (/agentio-plan, one at a time):"
    yt ideas | sed 's/^/  /'
    echo
    echo "Epics that would be resumed:"
    resumable_epics | sed 's/^/  /'
    echo
    echo "Ready epics (/agentio-work-epic in $WORKTREES_DIR/<ID>), free slots: $(( MAX_PARALLEL - $(running_count) )):"
    yt ready-epics | sed 's/^/  /'
    for epic in $(ready_epics); do
        echo "  $epic -- tree:"
        yt tree "$epic" | sed 's/^/    /'
    done
    echo
    yt blocked
}

kill_sessions() {
    local epic pid
    for epic in $(running_epics); do
        pid="$(cat "$LOG_DIR/$epic.pid")"
        kill -TERM -- "-$pid" 2>/dev/null || kill -TERM "$pid"
        log "$epic: sent SIGTERM to session $pid (claim kept; the next loop run resumes it)"
    done
}

case "$MODE" in
    dry-run) dry_run; exit 0 ;;
    kill) kill_sessions; exit 0 ;;
esac

if (( ${#MCP_CONFIGS[@]} == 0 )) || [[ -z "${AGENTIO_SESSION_SETTINGS:-}" || -z "${AGENTIO_PLANNING_SETTINGS:-}" ]]; then
    echo "AGENTIO_MCP_CONFIG or the session settings are not set: start the loop with php artisan agentio:run" >&2
    exit 1
fi

# One loop per checkout: loop.pid holds the pid of the running loop and is removed on exit.
if alive "$(cat "$LOOP_PID_FILE" 2>/dev/null)" && [[ "$(cat "$LOOP_PID_FILE")" != "$$" ]]; then
    echo "Another agent loop is already running (pid $(cat "$LOOP_PID_FILE"), $LOOP_PID_FILE)" >&2
    exit 1
fi
echo "$$" >"$LOOP_PID_FILE"
trap '[[ "$(cat "$LOOP_PID_FILE" 2>/dev/null)" == "$$" ]] && rm -f "$LOOP_PID_FILE"' EXIT

log "agent loop started: mode=$MODE policy=$MERGE_POLICY max_parallel=$MAX_PARALLEL interval=${INTERVAL}s${ONLY_EPIC:+ epic=$ONLY_EPIC}"

while true; do
    if stopping; then
        log "stop requested ($STOP_FILE or signal), exiting; running sessions: $(running_epics | tr '\n' ' ')"
        break
    fi

    reap
    dispatch_epics
    plan_ideas
    dispatch_epics

    if [[ "$MODE" == "once" ]]; then
        if (( WAIT == 1 )); then
            log "waiting for running sessions: $(running_epics | tr '\n' ' ')"
            while [[ -n "$(running_epics)" ]] && ! stopping; do sleep 10; done
            reap
        fi
        break
    fi

    for (( waited = 0; waited < INTERVAL; waited += 5 )); do
        stopping && break
        sleep 5
        reap
    done
done

log "agent loop stopped"
