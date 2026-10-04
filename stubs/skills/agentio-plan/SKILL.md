---
name: agentio-plan
description: Полный цикл планирования идеи проекта {{project}} — project-manager → system-analyst → laravel-architect → декомпозиция EPIC/STORY/TASK → Ready. Запускается человеком (/agentio-plan) или циклом агентов (php artisan agentio:run) для каждой идеи в Backlog.
argument-hint: <ID идеи {{project}}-N | текст идеи>
disable-model-invocation: true
allowed-tools: Skill Bash(php artisan agentio:yt *) mcp__youtrack__*
---

# /agentio-plan $ARGUMENTS

Ты выполняешь полный цикл планирования автономно: человек не отвечает в процессе. Вопросы задаются только через `[AGENT:BLOCKED]`. Первым делом загрузи скилл agentio-youtrack-workflow (Skill): правила процесса и инструменты MCP.

## Шаги

1. **Определи вход.**
   - Если `$ARGUMENTS` похож на ID (`{{project}}-\d+`) — это идея.
   - Иначе это текст идеи: создай задачу `[IDEA] <краткое summary>` с описанием = текст: `create_issue(project="{{project}}", summary=…, description=…, customFields={"Type": "Idea", "Stage": "Backlog"})`, метка `idea` (`manage_issue_tags`). Дальше работай с её ID.
   - Если у идеи уже есть эпики (`search_issues "project: {{project}} Type: Epic relates to: <IDEA>"`) и `[AGENT:DONE]` от планирования — это повторный запуск. Сверь состояние с процедурой ниже и доделай недостающее, ничего не дублируя.
2. **Примени скилл agentio-project-manager** (Skill) и пройди его процедуру планирования целиком:
   - захват идеи → требования;
   - **agentio-system-analyst** (Skill) → статьи модулей и фич, карта модулей, сводка влияния;
   - эпики → **agentio-laravel-architect** (Skill) для каждого эпика → проект, ADR, порядок реализации;
   - декомпозиция STORY/TASK → зависимости (включая пересечения по файлам);
   - `php artisan agentio:yt validate <IDEA>` → Ready → `[AGENT:DONE]` → `php artisan agentio:yt release <IDEA> --state=Done`.
3. Если на любом шаге нужен человек — `[AGENT:BLOCKED]` в идее, `php artisan agentio:yt release <IDEA> --state=Blocked` и остановись.

## Итог (последнее сообщение)

```
PLAN <IDEA>: DONE | BLOCKED
Эпики: {{project}}-.. (Ready), ...
Первая волна задач: {{project}}-.., {{project}}-..
Статьи: {{project}}-A-.., ...
```
