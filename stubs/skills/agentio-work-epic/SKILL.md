---
name: agentio-work-epic
description: Оркестратор эпика проекта {{project}} — захватывает EPIC, идёт по готовым TASK согласно зависимостям (перед каждой волной проверяет, не поставил ли человек эпик на паузу), отдаёт каждую задачу субагенту со скиллом agentio-develop-task (независимые — параллельно), после каждой STORY запускает субагента со скиллом agentio-review-story, в конце публикует ветку эпика pull request'ом в {{base_branch}} (php artisan agentio:pr) и переводит эпик в Review. Повторный запуск продолжает с места остановки по данным YouTrack. Запускается циклом агентов в worktree эпика.
argument-hint: <ID эпика {{project}}-N>
disable-model-invocation: true
allowed-tools: Skill Agent Bash(php artisan agentio:yt *) Bash(php artisan agentio:pr *) Bash(composer test*) Bash(vendor/bin/pest *) Bash(git *) mcp__youtrack__*
---

# /agentio-work-epic $ARGUMENTS

Ты — оркестратор эпика `$ARGUMENTS`. Сам код не пишешь: каждую TASK выполняет субагент со скиллом agentio-develop-task, каждую STORY проверяет субагент со скиллом agentio-review-story. Правила процесса — скилл agentio-youtrack-workflow (загрузи его через Skill первым делом). Работаешь автономно.

Параметры (из окружения, иначе значения по умолчанию): `MAX_PARALLEL_TASKS` (2), `BASE_BRANCH` ({{base_branch}}).

**Ветки и слияния.** Все TASK всех STORY эпика коммитятся прямо в ветку эпика в этом worktree — работа историй сливается в эпик автоматически и локально, без push и без pull request'ов. На GitHub уходит только ветка эпика, одним pull request'ом в `{{base_branch}}` (шаг 4). Сливает этот pull request только человек (панель `/agentio`, `php artisan agentio:accept`, Telegram-бот agentio) — ни ты, ни субагенты не сливаете ничего в `{{base_branch}}` и `{{production_branch}}`.

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
   - `ON_HOLD` (код 3) → остановись: эпик на паузе у человека; статусы не меняй.
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

0. **Пауза.** `php artisan agentio:yt state $ARGUMENTS`. Если `On Hold` — человек поставил эпик на паузу (`[AGENT:PAUSE]` в эпике): новую волну не начинай. Запиши в эпик `[AGENT:DECISION]` «Пауза: остановился после волны N, прогресс X/Y задач, в работе осталось: …» и заверши сессию с итогом `ON_HOLD`. Stage эпика, историй и задач не меняй, захват не снимай (`release` не вызывай): после снятия паузы цикл вернёт эпик в работу, и ты продолжишь с шага 2 (`RESUMED`).
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
5. **Разбор результатов.** `DONE` — ок. `LOST` — задачу взял кто-то другой, пропусти. `BLOCKED` — задача в Blocked, продолжай остальные. Субагент оборвался на лимите Claude Code (`rate_limit`, «hit your … limit», «usage limit reached», HTTP 429) — это не сбой задачи: не перезапускай её, не пиши `[AGENT:BLOCKED]` и не меняй статусы, а заверши сессию; цикл дождётся сброса лимита и возобновит эпик. Субагент вернулся без итоговой строки или с другой ошибкой — проверь задачу (`get_issue`). Если она не `Done` и не `Blocked`, перезапусти её **один раз**; при повторном сбое — `[AGENT:BLOCKED]` в задаче и `php artisan agentio:yt release <ID> --state=Blocked`.
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

Перед завершением ещё раз проверь паузу (шаг 3.0): эпик в `On Hold` — итог `ON_HOLD`, без `agentio:pr` и без смены статусов.

- **Все STORY в `Review` или `Done`:**
  0. **Синхронизация с `{{base_branch}}`** — ветка эпика получает то, что приняли в `{{base_branch}}` на GitHub после её создания (pull request'ы других эпиков), и полный прогон проверяет уже объединённый код: `git fetch origin {{base_branch}}`, затем `git merge --no-edit origin/{{base_branch}}`.

     При конфликте выполни `git diff --name-only --diff-filter=U` (список файлов), затем `git merge --abort`. Сам конфликты не разрешай: перечисли файлы в `[AGENT:DONE]` эпика в разделе «что осталось» — их разрешит человек при приёмке.
  1. `composer test` — полный прогон на ветке (единственный способ полного прогона; вывод не передавай в `| tail`/`| head`). Если красный — создай TASK-исправление в соответствующей STORY (Ready) и вернись в цикл (не более 2 раз, потом `[AGENT:BLOCKED]`).
  2. **Pull request эпика:** `php artisan agentio:pr $ARGUMENTS` — пушит ветку эпика в `origin` и открывает pull request в `{{base_branch}}` (или обновляет открытый, если эпик возвращался на доработку); последняя строка вывода — ссылка на PR. Сам не выполняй `git push` и `gh pr …`. Команда завершилась ошибкой — запиши её текст в «что осталось» `[AGENT:DONE]`: цикл повторит публикацию, когда эпик будет в Review.
  3. `[AGENT:DONE]` в эпик: STORY и их вердикты, список коммитов (`git log --oneline origin/{{base_branch}}..HEAD`), результат полного прогона, ссылка на pull request, как принять (слить PR: панель `/agentio`, `php artisan agentio:accept $ARGUMENTS` или «смержи $ARGUMENTS» в Telegram-боте), созданная смежная работа.
  4. `php artisan agentio:yt release $ARGUMENTS --state=Review`.
- **Работа встала** (остались только Blocked или ожидающие зависимостей вне эпика): `[AGENT:BLOCKED]` в эпик со списком причин (`php artisan agentio:yt blocked`), затем `php artisan agentio:yt release $ARGUMENTS --state=Blocked`. После ответов человек возвращает эпик в `Ready`, и цикл продолжит работу в том же worktree.

## Итог (последнее сообщение)

```
WORK-EPIC $ARGUMENTS: REVIEW | BLOCKED | IN_PROGRESS | ON_HOLD
Ветка: $ARGUMENTS · PR: <ссылка> · Коммиты: N · Полный прогон: passed/failed
STORY: {{project}}-.. Review, {{project}}-.. Blocked (<причина>)
```

## Запрещено

- Писать код самому, вместо субагента.
- `git push` (ветку эпика публикует только `php artisan agentio:pr`), слияние в `{{base_branch}}`/`BASE_BRANCH` и `{{production_branch}}`, `gh pr merge`, `--force`, `reset --hard`, удаление чужих веток.
- Отдельные ветки и pull request'ы для STORY и TASK.
- Брать задачи вне этого эпика.
- Менять статус задачи без соответствующего комментария `[AGENT:*]` (кроме STORY → In Progress в начале волны).
- Начинать новую волну, менять статусы или снимать захват эпика в `On Hold`.
