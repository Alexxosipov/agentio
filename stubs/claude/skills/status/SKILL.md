---
name: status
description: Сводка по проекту {{project}} — что в работе у агентов, что заблокировано и чем, что ждёт человека (ответы на [AGENT:BLOCKED], приёмка эпиков в Review с командами слияния), счётчики по статусам и состояние локального цикла агентов. Только чтение. Применять, когда спрашивают «как дела», «что происходит», «что от меня нужно».
allowed-tools: Bash(php scripts/yt.php *) Bash(git worktree list*) Bash(git log *) Bash(tail *)
---

# /status

**Только чтение.**

1. Данные:
   ```bash
   php scripts/yt.php status
   php scripts/yt.php blocked
   php scripts/yt.php claimed-epics
   git worktree list
   ls -t storage/logs/agents/ 2>/dev/null | head
   test -f .agent-stop && echo "STOP FLAG SET"
   ```
2. Для каждого эпика в `Review`: ветка (`git branch --list 'epic/<ID>-*'`), число коммитов (`git log --oneline {{base_branch}}..<ветка> | wc -l`) и из `[AGENT:DONE]` эпика — результат полного прогона и ссылка на PR, если есть.
3. Для каждого эпика в работе — последние 5 строк лога `storage/logs/agents/<ID>.log`, если он есть.

## Формат ответа

```
## Сводка {{project}}
Epic: Ready=1 In Progress=1 Review=0 … | Story: … | Task: …

## В работе
{{project}}-10 [EPIC] … — owner host:/path, лог: <последнее событие>

## Заблокировано
{{project}}-20 [TASK] … — <причина>; нужно: <что от человека>

## Ждёт человека
1. Ответить на вопросы: {{project}}-20 (ссылка), …
2. Принять эпики: {{project}}-10 — ветка epic/{{project}}-10-…, N коммитов, тесты passed.
   git -C . merge --no-ff epic/{{project}}-10-… && php scripts/yt.php release {{project}}-10 --state=Done

## Цикл агентов
Флаг остановки: нет · активные процессы: {{project}}-10 (pid …)
```
