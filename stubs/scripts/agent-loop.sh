#!/usr/bin/env bash
# Autonomous development loop: plans ideas and works on ready epics with Claude Code in headless mode.
#
#   scripts/agent-loop.sh                 run forever (every $AGENT_LOOP_INTERVAL seconds)
#   scripts/agent-loop.sh --once          one pass, then wait for the started epic sessions to finish
#   scripts/agent-loop.sh --dry-run       only show what would be started (changes nothing)
#   scripts/agent-loop.sh --kill          stop running agent sessions (claims stay; next run resumes them)
#
# Options: --interval=SEC  --max-parallel=N  --max-parallel-tasks=N  --epic={{project}}-N (only this epic)
#          --no-plan  --no-wait (with --once)
# Stop:    touch .agent-stop   (the loop exits after the current step; running sessions finish on their own)
#
# Env:  YOUTRACK_URL, YOUTRACK_TOKEN (required); AGENTIO_PROJECT={{project}}; MAX_PARALLEL=2; MAX_PARALLEL_TASKS=2;
#       AGENT_LOOP_INTERVAL=300; BASE_BRANCH={{base_branch}}; WORKTREES_DIR=<repo>/../worktrees; CLAUDE_BIN=claude;
#       CLAUDE_MODEL (optional); MERGE_POLICY (default: the MERGE_POLICY line of CLAUDE.md);
#       AGENT_LOG_DIR=<repo>/storage/logs/agents; MAX_RESTARTS=3 (crashed epic sessions resumed before Blocked)
# Files in AGENT_LOG_DIR: loop.log, loop.pid (this loop), <EPIC>.pid/.log (epic sessions), plan-<IDEA>.pid/.log.
# Each pass starts with `php scripts/yt.php sync-stage` (Stage follows State; errors are only logged).
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
LOG_DIR="${AGENT_LOG_DIR:-$ROOT/storage/logs/agents}"
STOP_FILE="$ROOT/.agent-stop"
LOOP_PID_FILE="$LOG_DIR/loop.pid"
INTERVAL="${AGENT_LOOP_INTERVAL:-300}"
MAX_PARALLEL="${MAX_PARALLEL:-2}"
MAX_PARALLEL_TASKS="${MAX_PARALLEL_TASKS:-2}"
MAX_RESTARTS="${MAX_RESTARTS:-3}"
BASE_BRANCH="${BASE_BRANCH:-{{base_branch}}}"
mkdir -p "${WORKTREES_DIR:-$ROOT/../worktrees}"
WORKTREES_DIR="$(cd "${WORKTREES_DIR:-$ROOT/../worktrees}" && pwd -P)"
CLAUDE_BIN="${CLAUDE_BIN:-claude}"
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
        -h|--help) sed -n '2,19p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "Unknown option: $arg" >&2; exit 64 ;;
    esac
done

if [[ -z "${MERGE_POLICY:-}" ]]; then
    MERGE_POLICY="$(sed -n 's/^MERGE_POLICY:[[:space:]]*`\{0,1\}\([a-z-]*\).*/\1/p' "$ROOT/CLAUDE.md" 2>/dev/null | head -n 1)"
fi
MERGE_POLICY="${MERGE_POLICY:-local-branch}"
export BASE_BRANCH WORKTREES_DIR MAX_PARALLEL_TASKS MERGE_POLICY AGENT_LOG_DIR="$LOG_DIR"

mkdir -p "$LOG_DIR"
cd "$ROOT" || exit 1

log() { echo "[$(date '+%F %T')] $*" | tee -a "$LOG_DIR/loop.log" >&2; }
yt() { php "$ROOT/scripts/yt.php" "$@"; }
alive() { [[ -n "${1:-}" ]] && kill -0 "$1" 2>/dev/null; }
stopping() { [[ -f "$STOP_FILE" || "${INTERRUPTED:-0}" == 1 ]]; }
ids() { php -r 'foreach (json_decode(stream_get_contents(STDIN), true) ?: [] as $row) { echo $row["id"], PHP_EOL; }'; }

if [[ -z "${YOUTRACK_URL:-}" || -z "${YOUTRACK_TOKEN:-}" ]]; then
    echo "YOUTRACK_URL and YOUTRACK_TOKEN must be set (export them, or start the loop with php artisan agentio:run)" >&2
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

# Settings of a headless session: .claude/agent-settings.json plus the permission rules of .claude/settings.json.
# Claude Code ignores the allow rules of .claude/settings.json in a directory whose workspace trust was never
# accepted (every new epic worktree), so they are passed with --settings; deny rules and hooks apply either way.
session_settings() {
    php -r '
        $read = function (string $file): array {
            $data = json_decode((string) @file_get_contents($file), true);
            return is_array($data) ? $data : [];
        };
        $settings = $read($argv[1]."/.claude/agent-settings.json");
        $project = $read($argv[1]."/.claude/settings.json");
        foreach (["allow", "deny"] as $list) {
            $rules = array_merge((array) ($project["permissions"][$list] ?? []), (array) ($settings["permissions"][$list] ?? []));
            if ($rules !== []) {
                $settings["permissions"][$list] = array_values(array_unique($rules, SORT_REGULAR));
            }
        }
        echo json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    ' "$1"
}

claude_headless() {
    local dir="$1" logfile="$2" prompt="$3" settings
    settings="$(session_settings "$dir")"
    (
        cd "$dir" || exit 1
        exec setsid "$CLAUDE_BIN" -p "$prompt" \
            --permission-mode dontAsk \
            --strict-mcp-config --mcp-config "$dir/.claude/agents-mcp.json" \
            --settings "$settings" \
            --output-format stream-json --verbose \
            ${CLAUDE_MODEL:+--model "$CLAUDE_MODEL"} \
            </dev/null >>"$logfile" 2>&1
    ) &
    echo $!
}

epic_state() { yt tree "$1" --json | php -r '$t = json_decode(stream_get_contents(STDIN), true); echo $t[0]["state"] ?? "";'; }

epic_branch() { git -C "$ROOT" for-each-ref --format='%(refname:short)' "refs/heads/epic/$1-*" | head -n 1; }

launch_epic() {
    local epic="$1" dir pid
    if ! git -C "$ROOT" cat-file -e "$BASE_BRANCH:.claude/agents-mcp.json" 2>/dev/null; then
        log "ERROR: agent infrastructure is not committed to $BASE_BRANCH; worktrees would not have it"
        return 1
    fi
    log "$epic: preparing worktree"
    if ! dir="$("$ROOT/scripts/epic-worktree.sh" "$epic" 2>>"$LOG_DIR/$epic.setup.log" | tail -n 1)" || [[ ! -d "$dir" ]]; then
        log "$epic: worktree setup failed, see $LOG_DIR/$epic.setup.log"
        return 1
    fi
    echo "===== $(date '+%F %T') /work-epic $epic in $dir =====" >>"$LOG_DIR/$epic.log"
    pid="$(claude_headless "$dir" "$LOG_DIR/$epic.log" "/work-epic $epic")"
    echo "$pid" >"$LOG_DIR/$epic.pid"
    log "$epic: started /work-epic (pid $pid, worktree $dir, log $LOG_DIR/$epic.log)"
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
                "$ROOT/scripts/epic-worktree.sh" "$epic" --remove && log "$epic: PR exists, worktree removed"
            elif [[ "$MERGE_POLICY" == "auto-merge" ]] && ! git -C "$ROOT" remote | grep -q .; then
                if [[ -z "$(git -C "$ROOT" status --porcelain --untracked-files=no)" && "$(git -C "$ROOT" branch --show-current)" == "$BASE_BRANCH" ]]; then
                    if git -C "$ROOT" merge --no-ff --no-edit "$branch" >>"$LOG_DIR/loop.log" 2>&1; then
                        log "$epic: auto-merged $branch into $BASE_BRANCH"
                    else
                        git -C "$ROOT" merge --abort 2>/dev/null
                        log "$epic: auto-merge failed (conflict), left for a human"
                    fi
                else
                    log "$epic: auto-merge skipped: main checkout is dirty or not on $BASE_BRANCH"
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
**Что мешает:** сессия /work-epic $MAX_RESTARTS раза подряд завершилась, не доведя эпик до Review.
**Что нужно от человека:** посмотреть лог \`$LOG_DIR/$epic.log\` на машине \`$HOST\` (\`php scripts/agent-log.php $epic\`), устранить причину и вернуть эпик в Ready." >/dev/null
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

# Stage (Kanban board columns) is derived from State; fix drift left by manual edits or MCP updates.
# Quiet: only fixed issues and errors are logged, and an error never stops the loop.
sync_stage() {
    local output line
    if output="$(yt sync-stage 2>&1)"; then
        while IFS= read -r line; do
            [[ -n "$line" && "$line" != *" issue(s) checked, 0 fixed" && "$line" != *"nothing to sync" ]] && log "sync-stage: $line"
        done <<<"$output"
    else
        log "sync-stage failed (ignored): $(tr '\n' ' ' <<<"$output")"
    fi
    return 0
}

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
        echo "===== $(date '+%F %T') /plan $idea =====" >>"$logfile"
        log "$idea: planning (log $logfile)"
        pid="$(claude_headless "$ROOT" "$logfile" "/plan $idea")"
        echo "$pid" >"$LOG_DIR/plan-$idea.pid"
        while alive "$pid"; do sleep 5; done
        rm -f "$LOG_DIR/plan-$idea.pid"
        log "$idea: planning finished"
    done
}

dry_run() {
    local epic
    echo "== Agent loop dry run ($(date '+%F %T')) =="
    echo "PROJECT=${AGENTIO_PROJECT:-{{project}}} MERGE_POLICY=$MERGE_POLICY MAX_PARALLEL=$MAX_PARALLEL MAX_PARALLEL_TASKS=$MAX_PARALLEL_TASKS BASE_BRANCH=$BASE_BRANCH WORKTREES_DIR=$WORKTREES_DIR"
    echo "Running sessions: $(running_epics | tr '\n' ' ')"
    echo
    echo "Ideas that would be planned (/plan, one at a time):"
    yt ideas | sed 's/^/  /'
    echo
    echo "Epics that would be resumed:"
    resumable_epics | sed 's/^/  /'
    echo
    echo "Ready epics (/work-epic in $WORKTREES_DIR/<ID>), free slots: $(( MAX_PARALLEL - $(running_count) )):"
    yt ready-epics | sed 's/^/  /'
    for epic in $(ready_epics); do
        echo "  $epic -- first wave of tasks:"
        yt ready-tasks "$epic" | sed 's/^/    /'
        echo "  $epic -- tree:"
        yt tree "$epic" | sed 's/^/    /'
    done
    echo
    yt blocked
    echo
    echo "Stage drift that the next pass would fix (sync-stage):"
    yt sync-stage --dry-run | sed 's/^/  /'
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

    sync_stage
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
