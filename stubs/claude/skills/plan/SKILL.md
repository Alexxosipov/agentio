---
name: plan
description: Полный цикл планирования идеи — project-manager → system-analyst → laravel-architect → декомпозиция EPIC/STORY/TASK → Ready. Запускается человеком или scripts/agent-loop.sh.
argument-hint: <ID идеи {{project}}-N | текст идеи>
disable-model-invocation: true
allowed-tools: Bash(php scripts/yt.php *) mcp__youtrack__*
---

# /plan $ARGUMENTS

Ты выполняешь полный цикл планирования автономно: человек не отвечает в процессе. Вопросы задаются только через `[AGENT:BLOCKED]`.

## Шаги

1. **Определи вход.**
   - Если `$ARGUMENTS` похож на ID (`{{project}}-\d+`) — это идея.
   - Иначе это текст идеи: создай задачу `[IDEA] <краткое summary>` с описанием = текст и `customFields={"Type": "Idea", "State": "Backlog", "Stage": "Backlog"}`, метка `idea`. Дальше работай с её ID.
   - Если у идеи уже есть эпики (`relates to`) и `[AGENT:DONE]` от планирования — это повторный запуск. Сверь состояние с процедурой ниже и доделай недостающее, ничего не дублируя.
2. **Примени скилл `project-manager`** (вызови его через Skill) и пройди его процедуру планирования целиком:
   - захват идеи → требования;
   - **`system-analyst`** (Skill) → статьи модулей и фич, карта модулей, сводка влияния;
   - эпики → **`laravel-architect`** (Skill) для каждого эпика → проект, ADR, порядок реализации;
   - декомпозиция STORY/TASK → зависимости (включая пересечения по файлам);
   - `php scripts/yt.php validate <IDEA>` → Ready (`php scripts/yt.php set-state <ID> Ready`) → `[AGENT:DONE]` → `release --state=Done`.
   - Статусы меняй только через `set-state` / `release` (они синхронизируют Stage), не через `update_issue`.
3. Если на любом шаге нужен человек — `[AGENT:BLOCKED]` в идее, `php scripts/yt.php release <IDEA> --state=Blocked` и остановись.

## Итог (последнее сообщение)

```
PLAN <IDEA>: DONE | BLOCKED
Эпики: {{project}}-.. (Ready), ...
Первая волна задач: {{project}}-.., {{project}}-..
Статьи: {{project}}-A-.., ...
```
