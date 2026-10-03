# Автономная разработка (agentio) — правила для Claude Code

Блок ставит и обновляет `php artisan agentio:install`: правьте его там, а не здесь (изменения между маркерами затираются при переустановке). Стек и конвенции проекта описаны в остальной части `CLAUDE.md` и в ADR-001 базы знаний ({{kb.adr.001}}).

## Источник правды — YouTrack, проект `{{project}}`
Задачи, их связи, контекст работы агентов и документация (база знаний) живут в YouTrack: MCP `youtrack` и `php scripts/yt.php` (нужны переменные окружения `YOUTRACK_URL`, `YOUTRACK_TOKEN`). Не храни контекст работы в файлах репозитория.

## Обязательные правила для агентов
1. Перед любой работой с задачей примени скилл `youtrack-workflow` и прочитай контекст: `php scripts/yt.php context <ID>` (задача, родители, статьи, все `[AGENT:*]` комментарии).
2. Не работай над задачей без захвата: `php scripts/yt.php claim <ID> ...`; при `LOST` — не трогай.
3. Фиксируй ход работы комментариями `[AGENT:START]`, `[AGENT:DECISION]`, `[AGENT:BLOCKED]`, `[AGENT:DONE]` в момент события. Статус (State) меняй только через `php scripts/yt.php set-state|claim|release` — они же ведут поле Stage (колонки Kanban-доски).
4. Делай только то, что в задаче; смежную работу оформляй новой TASK.
5. Тесты запускай только через `scripts/run-tests.sh` (никогда `<тесты> | tail`); полный гейт — `RUN_TESTS_FULL=1 scripts/run-tests.sh`. Коммить свои файлы через `scripts/agent-commit.sh <TASK> "<msg>" <files>`.
6. Не добавляй зависимости (composer, npm/bun) без согласования — оформи `[AGENT:BLOCKED]`.

## Скиллы, субагенты, команды
- Скиллы: `youtrack-workflow`, `project-manager`, `system-analyst`, `laravel-architect` (+ скиллы Laravel Boost, если установлен).
- Субагенты: `task-developer` (одна TASK), `story-reviewer` (одна STORY).
- Команды: `/plan <ID|текст>`, `/work-epic <ID>`, `/dispatch`, `/status`.
- Автономный цикл: `php artisan agentio:run` (обёртка над `scripts/agent-loop.sh`) — инструкция в `docs/AUTONOMOUS_WORKFLOW.md`.

## Политика слияния
MERGE_POLICY: {{merge_policy}}

Значения: `local-branch` — ветка `epic/*` остаётся локально, сливает человек; `pull-request` — push ветки и PR через `gh`, сливает человек; `auto-merge` — слияние автоматически при зелёном полном прогоне (через `gh pr merge --auto`, без remote — локальный merge в `{{base_branch}}` скриптом цикла). Базовая ветка — `{{base_branch}}`. Push в `{{base_branch}}` агентам запрещён при любой политике.
