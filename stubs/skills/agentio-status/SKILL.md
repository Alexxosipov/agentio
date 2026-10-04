---
name: agentio-status
description: Сводка по проекту {{project}} — что в работе у агентов, что заблокировано и чем, что ждёт человека (ответы на [AGENT:BLOCKED], приёмка эпиков в Review с командами слияния), счётчики по статусам и состояние локального цикла агентов. Только чтение. Применять, когда спрашивают «как дела», «что происходит», «что от меня нужно».
allowed-tools: Bash(php artisan agentio:status*) Bash(php artisan agentio:yt *) Bash(php artisan agentio:log *) Bash(git worktree list*) Bash(git log *) Bash(git branch *)
---

# /agentio-status

**Только чтение.**

1. Данные:
   ```bash
   php artisan agentio:status
   php artisan agentio:yt blocked
   php artisan agentio:yt claimed-epics
   git worktree list
   ```
2. Для каждого эпика в `Review`: ветка (`git branch --list 'epic/<ID>-*'`), число коммитов (`git log --oneline {{base_branch}}..<ветка>`) и из `[AGENT:DONE]` эпика (`get_issue_comments`) — результат полного прогона и ссылка на PR, если есть.
3. Для каждого эпика в работе — последние события сессии: `php artisan agentio:log <ID> --lines=5`.

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
   git merge --no-ff epic/{{project}}-10-… && php artisan agentio:yt release {{project}}-10 --state=Done

## Цикл агентов
Флаг остановки: нет · активные сессии: {{project}}-10 (pid …)
```
