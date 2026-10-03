<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Runtime;

enum SessionEventType: string
{
    /** "===== <time> /work-epic TP-2 in <dir> =====" written by the loop before a session starts. */
    case SessionStart = 'session_start';

    /** The session initialised (model, working directory). */
    case Init = 'init';

    /** Text written by the agent. */
    case Text = 'text';

    /** A tool call (Bash, Edit, MCP tools, ...). */
    case ToolUse = 'tool_use';

    /** A subagent started with the Agent (Task) tool. */
    case Subagent = 'subagent';

    /** A background task (subagent or shell) finished. */
    case TaskFinished = 'task_finished';

    /** A tool call returned an error. */
    case ToolError = 'tool_error';

    /** A tool call was denied by the permission settings. */
    case PermissionDenied = 'permission_denied';

    /** A turn finished: result text, cost and duration. */
    case Result = 'result';
}
