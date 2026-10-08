---
name: agentio-dispatch
description: Показывает, что цикл агентов проекта {{project}} может запустить прямо сейчас — готовые эпики (по полному правилу готовности) с первой волной задач, идеи без плана, эпики в работе с владельцем и состоянием worktree, заблокированные задачи с причинами. Только чтение, ничего не меняет. Применять, когда спрашивают «что готово к работе», «что запустится», «почему задача не берётся».
allowed-tools: Bash(php artisan agentio:yt *) Bash(php artisan agentio:run --dry-run*) Bash(git worktree list*)
---

# /agentio-dispatch

**Только чтение.** Не меняй ничего ни в YouTrack, ни в git: никаких claim, release, update и комментариев.

1. Собери данные (команды независимы):
   ```bash
   php artisan agentio:yt ideas
   php artisan agentio:yt ready-epics
   php artisan agentio:yt claimed-epics
   php artisan agentio:yt blocked
   git worktree list
   php artisan agentio:status --local
   ```
2. Для каждого готового эпика покажи его первую волну задач (`ready-tasks` уже в выводе `ready-epics`).
3. Для эпиков в работе — есть ли его worktree в `git worktree list` и живая сессия в выводе `agentio:status --local`. Эпик `In Progress` без живой сессии при существующем worktree будет **возобновлён** циклом. Без worktree на этой машине — им владеет другая машина или захват устарел (см. owner).

## Формат ответа

```
Идеи к планированию:     {{project}}-1 …
Готовые эпики:           {{project}}-10 ветка {{project}}-10 · первая волна: {{project}}-14, {{project}}-15
В работе:                {{project}}-11 owner=host:/path · сессия жива/нет · будет возобновлён да/нет
Заблокировано (ждёт человека):
  {{project}}-20 <summary>
    <причина из [AGENT:BLOCKED]>
Ждут зависимостей:       {{project}}-16 ← {{project}}-14 (In Progress)
На паузе / отложено:     {{project}}-12 (эпик на паузе: не запустится до agentio:resume) · {{project}}-7 (отложенная идея)
```

Эпик в `On Hold` поставлен человеком на паузу (`[AGENT:PAUSE]`): цикл его не запускает, а его зависимые эпики ждут. Идея в `On Hold` с меткой `parked` проанализирована и отложена: в разработку её берёт только человек. Список — `php artisan agentio:status` (раздел «On hold»).

Закончи одной строкой: что запустит следующий проход цикла (`php artisan agentio:run --dry-run`, с учётом `MAX_PARALLEL`).
