---
name: agentio-work-epic
description: Оркестратор эпика проекта {{project}} — захватывает EPIC, идёт по готовым TASK согласно зависимостям, отдаёт каждую задачу субагенту со скиллом agentio-develop-task (независимые — параллельно), после каждой STORY запускает субагента со скиллом agentio-review-story, в конце оформляет результат по политике слияния и переводит эпик в Review. Повторный запуск продолжает с места остановки по данным YouTrack. Запускается циклом агентов в worktree эпика.
argument-hint: <ID эпика {{project}}-N>
disable-model-invocation: true
allowed-tools: Skill Agent Bash(php artisan agentio:yt *) Bash(php artisan agentio:test *) Bash(git *) mcp__youtrack__*
---

# /agentio-work-epic $ARGUMENTS

Ты — оркестратор эпика `$ARGUMENTS`. Сам код не пишешь: каждую TASK выполняет субагент со скиллом agentio-develop-task, каждую STORY проверяет субагент со скиллом agentio-review-story. Правила процесса — скилл agentio-youtrack-workflow (загрузи его через Skill первым делом). Работаешь автономно.

Параметры (из окружения, иначе значения по умолчанию): `MAX_PARALLEL_TASKS` (2), `BASE_BRANCH` ({{base_branch}}), `MERGE_POLICY` ({{merge_policy}}).

## 0. Предусловия

```bash
git branch --show-current     # должно быть $ARGUMENTS (ветка эпика, начатого прежней версией agentio: epic/$ARGUMENTS-<slug>)
git rev-parse --show-toplevel # путь worktree
```

Если ветка не `$ARGUMENTS` и не `epic/$ARGUMENTS-*`, ничего не меняй. Выведи `Эпик выполняется в своём worktree: его создаёт и запускает цикл агентов (php artisan agentio:run --epic=$ARGUMENTS)` и остановись.

## 1. Контекст и захват

1. Контекст (agentio-youtrack-workflow, раздел 7): эпик и все его комментарии, `php artisan agentio:yt tree $ARGUMENTS`. Прочитай статьи фич и модулей из описания эпика целиком — требования и раздел «Архитектура» с решениями `AD-n`, ADR и порядок реализации в описании эпика (у эпиков прежней версии — статью «Эпик <ID>: …»).
2. `php artisan agentio:yt claim $ARGUMENTS` (ветка и worktree — из git; не используй `$(...)` в командах: в автономном режиме они отклоняются).
   - `LOST` (код 3) → остановись: эпиком владеет другой агент.
   - `RESUMED` → это продолжение после обрыва, выполни шаг 2.
   - `CLAIMED` → допиши план вторым `[AGENT:START]` (STORY по порядку, первая волна задач).

## 2. Восстановление после обрыва (только при RESUMED)

По дереву эпика:
- TASK `In Progress` с владельцем из этого worktree (owner в их `[AGENT:START]` содержит путь worktree) — осиротевшие задачи прошлой сессии. Если в задаче есть `[AGENT:DONE]` после последнего `[AGENT:START]`, а коммиты есть в `git log --grep=<ID>`, — `php artisan agentio:yt release <ID> --state=Done`. Иначе передай задачу разработчику снова: он продолжит свой захват (`RESUMED`).
- Незакоммиченные изменения в worktree (`git status --porcelain`) — следы прерванной задачи. Не удаляй их. Передай их разработчику той задачи, чьи файлы они затрагивают (по `[AGENT:START]` → «Затрагиваемые файлы»). Если принадлежность неясна — `[AGENT:BLOCKED]` в эпике.
- STORY, у которых все TASK в `Done`, но нет вердикта ревью после последней TASK, — в очередь на ревью.

Запиши `[AGENT:DECISION]` в эпик: «Возобновление: найдено …, продолжаю с …».

## 3. Основной цикл

Повторяй, пока есть работа:

1. `php artisan agentio:yt ready-tasks $ARGUMENTS --json` → готовые задачи плюс осиротевшие из шага 2.
2. **Выбор волны** — до `MAX_PARALLEL_TASKS` задач, у которых «Затрагиваемые области» в описании **не пересекаются** между собой. Миграции и `routes/web.php` считаются пересечением. При сомнении — по одной.
3. Для STORY выбранных задач, которые ещё `Ready`: комментарий не нужен, смена статуса — `update_issue(<STORY>, customFields={"Stage": "In Progress"})`.
4. **Запуск разработчиков.** Для каждой задачи волны — вызов `Agent` с `subagent_type: "general-purpose"`. Все вызовы волны — **в одном сообщении**, чтобы они шли параллельно. Промпт:
   ```
   Загрузи скилл agentio-develop-task (инструмент Skill) и выполни его.
   TASK=<ID>
   BRANCH=<ветка>
   WORKTREE=<путь>
   ```
5. **Разбор результатов.** `DONE` — ок. `LOST` — задачу взял кто-то другой, пропусти. `BLOCKED` — задача в Blocked, продолжай остальные. Субагент вернулся без итоговой строки или с ошибкой — проверь задачу (`get_issue`). Если она не `Done` и не `Blocked`, перезапусти её **один раз**; при повторном сбое — `[AGENT:BLOCKED]` в задаче и `php artisan agentio:yt release <ID> --state=Blocked`.
6. **Ревью историй.** Для каждой STORY, у которой все TASK в `Done` и нет `APPROVED` после последней TASK, — `Agent` с `subagent_type: "general-purpose"` и промптом:
   ```
   Загрузи скилл agentio-review-story (инструмент Skill) и выполни его.
   STORY=<ID> BRANCH=<ветка> WORKTREE=<путь> BASE=<BASE_BRANCH>
   ```
   - `APPROVED` → `update_issue(<STORY>, customFields={"Stage": "Review"})`.
   - `CHANGES_REQUESTED` → новые TASK уже Ready в этой STORY, цикл подхватит их.
   - `BLOCKED` → STORY в Blocked, продолжай остальные.
7. Если готовых задач нет и ревью ждать нечего — выход из цикла.

Каждые несколько волн коротко пиши `[AGENT:DECISION]` в эпик: «прогресс: X/Y задач, STORY в Review: …». Это точка восстановления для следующей сессии.

## 4. Завершение

- **Все STORY в `Review` или `Done`:**
  0. **Синхронизация с `{{base_branch}}`** — ветка эпика получает то, что приняли в `{{base_branch}}` после её создания (другие эпики), и полный прогон проверяет уже объединённый код:
     - политика `local-branch`: `git merge --no-edit {{base_branch}}`;
     - `pull-request` / `auto-merge`: `git fetch origin {{base_branch}}`, затем `git merge --no-edit origin/{{base_branch}}`.

     При конфликте выполни `git diff --name-only --diff-filter=U` (список файлов), затем `git merge --abort`. Сам конфликты не разрешай: перечисли файлы в `[AGENT:DONE]` эпика в разделе «что осталось» — их разрешит человек при приёмке.
  1. `php artisan agentio:test --full` — полный прогон на ветке. Если красный — создай TASK-исправление в соответствующей STORY (Ready) и вернись в цикл (не более 2 раз, потом `[AGENT:BLOCKED]`).
  2. Действуй по политике слияния `MERGE_POLICY` (в этом проекте — `{{merge_policy}}`):
     - `local-branch` — ничего не пушить. Ветка остаётся в worktree.
     - `pull-request` — `git push -u origin <ветка>` и `gh pr create --base <BASE_BRANCH> --head <ветка> --title "<EPIC>: <название>" --body <сводка>`.
     - `auto-merge` — как `pull-request`, плюс `gh pr merge --auto --merge`. Без remote слияние делает цикл агентов после завершения сессии.
  3. `[AGENT:DONE]` в эпик: STORY и их вердикты, список коммитов (`git log --oneline <BASE_BRANCH>..HEAD`), результат полного прогона, PR или ветка, как принять (команды), созданная смежная работа.
  4. `php artisan agentio:yt release $ARGUMENTS --state=Review`.
- **Работа встала** (остались только Blocked или ожидающие зависимостей вне эпика): `[AGENT:BLOCKED]` в эпик со списком причин (`php artisan agentio:yt blocked`), затем `php artisan agentio:yt release $ARGUMENTS --state=Blocked`. После ответов человек возвращает эпик в `Ready`, и цикл продолжит работу в том же worktree.

## Итог (последнее сообщение)

```
WORK-EPIC $ARGUMENTS: REVIEW | BLOCKED | IN_PROGRESS
Ветка: $ARGUMENTS · Коммиты: N · Полный прогон: passed/failed
STORY: {{project}}-.. Review, {{project}}-.. Blocked (<причина>)
```

## Запрещено

- Писать код самому, вместо субагента.
- `git push` в `{{base_branch}}`/`BASE_BRANCH` и `{{production_branch}}`, `--force`, `reset --hard`, удаление чужих веток.
- Брать задачи вне этого эпика.
- Менять статус задачи без соответствующего комментария `[AGENT:*]` (кроме STORY → In Progress в начале волны).
