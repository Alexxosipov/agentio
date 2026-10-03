---
name: dispatch
description: Показывает, что можно запускать прямо сейчас — готовые эпики (по полному правилу готовности), идеи без плана, эпики в работе с владельцем и состоянием worktree, а также заблокированные задачи с причинами. Только чтение, ничего не меняет. Применять, когда спрашивают «что готово к работе», «что запустится», «почему задача не берётся».
allowed-tools: Bash(php scripts/yt.php *) Bash(git worktree list*) Bash(scripts/agent-loop.sh --dry-run*)
---

# /dispatch

**Только чтение.** Не меняй ничего ни в YouTrack, ни в git: никаких claim, release, update и комментариев.

1. Собери данные (команды независимы):
   ```bash
   php scripts/yt.php ideas
   php scripts/yt.php ready-epics
   php scripts/yt.php claimed-epics
   php scripts/yt.php blocked
   git worktree list
   ls storage/logs/agents/ 2>/dev/null
   ```
2. Для каждого готового эпика покажи его первую волну задач (`ready-tasks` уже в выводе `ready-epics`).
3. Для эпиков в работе — есть ли локальный worktree `../worktrees/<ID>` и живой процесс (`storage/logs/agents/<ID>.pid` → `kill -0 <pid>`). Эпик `In Progress` без живого процесса при существующем worktree будет **возобновлён** циклом. Без worktree на этой машине — им владеет другая машина или захват устарел (см. owner).

## Формат ответа

```
Идеи к планированию:     {{project}}-1 …
Готовые эпики:           {{project}}-10 epic/{{project}}-10-<slug> · первая волна: {{project}}-14, {{project}}-15
В работе:                {{project}}-11 owner=host:/path · процесс жив/нет · будет возобновлён да/нет
Заблокировано (ждёт человека):
  {{project}}-20 <summary>
    <причина из [AGENT:BLOCKED]>
Ждут зависимостей:       {{project}}-16 ← {{project}}-14 (In Progress)
```

Закончи одной строкой: что запустит следующий проход `scripts/agent-loop.sh --once` (с учётом `MAX_PARALLEL`).
