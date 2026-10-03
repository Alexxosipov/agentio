---
name: work-epic
description: Оркестратор эпика — захватывает EPIC, идёт по готовым TASK согласно зависимостям, отдаёт задачи субагентам task-developer (независимые — параллельно), после каждой STORY запускает story-reviewer, в конце оформляет результат по политике слияния и переводит эпик в Review. Повторный запуск продолжает с места остановки по данным YouTrack. Запускается в worktree эпика.
argument-hint: <ID эпика {{project}}-N>
disable-model-invocation: true
allowed-tools: Bash(php scripts/yt.php *) Bash(scripts/run-tests.sh *) Bash(git *) mcp__youtrack__*
---

# /work-epic $ARGUMENTS

Ты — оркестратор эпика `$ARGUMENTS`. Сам код не пишешь: каждую TASK выполняет субагент `task-developer`, каждую STORY проверяет `story-reviewer`. Правила процесса — скилл `youtrack-workflow` (загрузи его через Skill первым делом). Работаешь автономно.

Параметры (из окружения, иначе значения по умолчанию): `MAX_PARALLEL_TASKS` (2), `BASE_BRANCH` ({{base_branch}}), `MERGE_POLICY` (из `CLAUDE.md`, раздел «Политика слияния»).

## 0. Предусловия

```bash
git branch --show-current     # должно быть epic/$ARGUMENTS-<slug>
git rev-parse --show-toplevel # путь worktree
```

Если ветка не `epic/$ARGUMENTS-*`, ничего не меняй. Выведи `Запусти в worktree: scripts/epic-worktree.sh $ARGUMENTS && cd ../worktrees/$ARGUMENTS && claude "/work-epic $ARGUMENTS"` и остановись.

## 1. Контекст и захват

1. `php scripts/yt.php context $ARGUMENTS` и `php scripts/yt.php tree $ARGUMENTS`. Прочитай статью проекта эпика и ADR (ссылки в описании).
2. `php scripts/yt.php claim $ARGUMENTS` (ветка и worktree — из git; не используй `$(...)` в командах: в автономном режиме они отклоняются).
   - `LOST` (код 3) → остановись: эпиком владеет другой агент.
   - `RESUMED` → это продолжение после обрыва, выполни шаг 2.
   - `CLAIMED` → допиши план вторым `[AGENT:START]` (STORY по порядку, первая волна задач).

## 2. Восстановление после обрыва (только при RESUMED)

По дереву эпика:
- TASK `In Progress` с владельцем из этого worktree (`claimOwner` в `context` содержит путь worktree) — осиротевшие задачи прошлой сессии. Если в задаче есть `[AGENT:DONE]` после последнего `[AGENT:START]`, а коммиты есть в `git log --grep=<ID>`, — `release <ID> --state=Done`. Иначе передай задачу `task-developer` снова: он продолжит свой захват (`RESUMED`).
- Незакоммиченные изменения в worktree (`git status --porcelain`) — следы прерванной задачи. Не удаляй их. Передай их `task-developer` той задачи, чьи файлы они затрагивают (по `[AGENT:START]` → «Затрагиваемые файлы»). Если принадлежность неясна — `[AGENT:BLOCKED]` в эпике.
- STORY, у которых все TASK в `Done`, но нет вердикта ревью после последней TASK, — в очередь на ревью.

Запиши `[AGENT:DECISION]` в эпик: «Возобновление: найдено …, продолжаю с …».

## 3. Основной цикл

Повторяй, пока есть работа:

1. `php scripts/yt.php ready-tasks $ARGUMENTS --json` → готовые задачи плюс осиротевшие из шага 2.
2. **Выбор волны** — до `MAX_PARALLEL_TASKS` задач, у которых «Затрагиваемые области» в описании **не пересекаются** между собой. Миграции и `routes/web.php` считаются пересечением. При сомнении — по одной.
3. Для STORY выбранных задач, которые ещё `Ready`, — `php scripts/yt.php set-state <STORY> "In Progress"`.
4. **Запуск субагентов.** Для каждой задачи волны — вызов `Agent` с `subagent_type: "task-developer"`. Все вызовы волны — **в одном сообщении**, чтобы они шли параллельно. Промпт:
   ```
   TASK=<ID>
   BRANCH=<ветка>
   WORKTREE=<путь>
   Реализуй задачу по своей процедуре. Сначала прочитай контекст через php scripts/yt.php context <ID>.
   ```
5. **Разбор результатов.** `DONE` — ок. `LOST` — задачу взял кто-то другой, пропусти. `BLOCKED` — задача в Blocked, продолжай остальные. Субагент вернулся без итоговой строки или с ошибкой — проверь задачу в YouTrack (`context`). Если она не `Done` и не `Blocked`, перезапусти её **один раз**; при повторном сбое — `[AGENT:BLOCKED]` в задаче и `release --state=Blocked`.
6. **Ревью историй.** Для каждой STORY, у которой все TASK в `Done` и нет `APPROVED` после последней TASK, — `Agent` с `subagent_type: "story-reviewer"`, промпт `STORY=<ID> BRANCH=<ветка> WORKTREE=<путь> BASE=<BASE_BRANCH>`.
   - `APPROVED` → `php scripts/yt.php set-state <STORY> Review`.
   - `CHANGES_REQUESTED` → новые TASK уже Ready в этой STORY, цикл подхватит их.
   - `BLOCKED` → STORY в Blocked, продолжай остальные.
7. Если готовых задач нет и ревью ждать нечего — выход из цикла.

Каждые несколько волн коротко пиши `[AGENT:DECISION]` в эпик: «прогресс: X/Y задач, STORY в Review: …». Это точка восстановления для следующей сессии.

## 4. Завершение

- **Все STORY в `Review` или `Done`:**
  1. `RUN_TESTS_FULL=1 scripts/run-tests.sh` — полный прогон на ветке. Если красный — создай TASK-исправление в соответствующей STORY (Ready) и вернись в цикл (не более 2 раз, потом `[AGENT:BLOCKED]`).
  2. Действуй по `MERGE_POLICY` из `CLAUDE.md`:
     - `local-branch` — ничего не пушить. Ветка остаётся в worktree.
     - `pull-request` — `git push -u origin <ветка>` и `gh pr create --base <BASE_BRANCH> --head <ветка> --title "<EPIC>: <название>" --body <сводка>`.
     - `auto-merge` — как `pull-request`, плюс `gh pr merge --auto --merge`. При отсутствии remote слияние делает `scripts/agent-loop.sh` после завершения сессии.
  3. `[AGENT:DONE]` в эпик: STORY и их вердикты, список коммитов (`git log --oneline <BASE_BRANCH>..HEAD`), результат полного прогона, PR или ветка, как принять (команды), созданная смежная работа.
  4. `php scripts/yt.php release $ARGUMENTS --state=Review`.
- **Работа встала** (остались только Blocked или ожидающие зависимостей вне эпика): `[AGENT:BLOCKED]` в эпик со списком причин (`php scripts/yt.php blocked`), затем `release $ARGUMENTS --state=Blocked`. После ответов человек возвращает эпик в `Ready`, и цикл продолжит работу в том же worktree.

## Итог (последнее сообщение)

```
WORK-EPIC $ARGUMENTS: REVIEW | BLOCKED | IN_PROGRESS
Ветка: epic/... · Коммиты: N · Полный прогон: passed/failed
STORY: {{project}}-.. Review, {{project}}-.. Blocked (<причина>)
```

## Запрещено

- Писать код самому, вместо субагента.
- `git push` в `{{base_branch}}`/`BASE_BRANCH`, `--force`, `reset --hard`, удаление чужих веток.
- Брать задачи вне этого эпика.
- Менять статус задачи без соответствующего комментария `[AGENT:*]`.
- Менять State через `update_issue` / `customFields` — только `set-state`, `claim`, `release` (они синхронизируют Stage).
