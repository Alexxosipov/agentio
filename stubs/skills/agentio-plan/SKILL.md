---
name: agentio-plan
description: Полный цикл планирования идеи проекта {{project}} — project-manager → system-analyst → laravel-architect → декомпозиция EPIC/STORY/TASK → Ready; отложенную идею (метка parked) — только до системного анализа в статью «Идеи». Запускается человеком (/agentio-plan) или циклом агентов (php artisan agentio:run) для каждой идеи в Backlog.
argument-hint: <ID идеи {{project}}-N | [--park] текст идеи>
disable-model-invocation: true
allowed-tools: Skill Bash(php artisan agentio:yt *) mcp__youtrack__*
---

# /agentio-plan $ARGUMENTS

Ты выполняешь полный цикл планирования автономно: человек не отвечает в процессе. Вопросы задаются только через `[AGENT:BLOCKED]`. Первым делом загрузи скилл agentio-youtrack-workflow (Skill): правила процесса и инструменты MCP.

## Шаги

1. **Определи вход.**
   - Если `$ARGUMENTS` похож на ID (`{{project}}-\d+`) — это идея.
   - Иначе это текст идеи: создай задачу `[IDEA] <краткое summary>` с описанием = текст: `create_issue(project="{{project}}", summary=…, description=…, customFields={"Type": "Idea", "Stage": "Backlog"})`, метка `idea` (`manage_issue_tags`). Если текст начинается с `--park` (или человек просит «на потом», «пока не делать»), это отложенная идея: `--park` в описание не попадает, добавь и метку `parked`. Дальше работай с её ID.
   - **Отложенная идея** (метка `parked`): планирование идёт только до системного анализа включительно — анализ в статью «Идеи» ({{kb.ideas}}), без эпиков, идея → `On Hold` (раздел «Отложенная идея» скилла agentio-project-manager).
   - **Отложенная идея взята в работу** (метки `parked` нет, но у идеи есть статья «Идея <ID>: …» под «Идеями» со статусом «отложена» и `[AGENT:DONE]` «Идея проанализирована и отложена»): сначала перенос анализа в «Системную аналитику» (скилл agentio-system-analyst, раздел «Отложенная идея принята в разработку»), затем обычное планирование с эпиками.
   - Если у идеи уже есть эпики (`search_issues "project: {{project}} Type: Epic relates to: <IDEA>"`) и `[AGENT:DONE]` от планирования — это повторный запуск. Сверь состояние с процедурой ниже и доделай недостающее, ничего не дублируя.
   - Если в идее есть `[AGENT:BLOCKED]` — человек ответил и вернул идею в Backlog: сначала перенеси ответы в статьи по разделу «Вопросы к человеку» скилла agentio-youtrack-workflow, затем продолжай с шага, на котором планирование остановилось.
2. **Примени скилл agentio-project-manager** (Skill) и пройди его процедуру планирования целиком:
   - захват идеи → требования;
   - **agentio-system-analyst** (Skill) → статьи модулей и фич (с особенностями системы), карта модулей, сводка влияния; оба скилла ниже и аналитик опираются на скилл `laravel-best-practices`, если он есть в проекте;
   - эпики → **agentio-laravel-architect** (Skill) для каждого эпика → решения в разделах «Архитектура» статей фич и модулей, ADR, порядок реализации в описании эпика;
   - декомпозиция STORY/TASK → зависимости (включая пересечения по файлам);
   - `php artisan agentio:yt validate <IDEA>` → Ready → `[AGENT:DONE]` → `php artisan agentio:yt release <IDEA> --state=Done`;
   - у отложенной идеи — после аналитика сразу `[AGENT:DONE]` «Идея проанализирована и отложена» → `php artisan agentio:yt release <IDEA> --state=OnHold`, без эпиков и архитектуры.
3. Если на любом шаге нужен человек — доделай всё, что от ответа не зависит, и задай все вопросы одним `[AGENT:BLOCKED]` в идее (раздел «Вопросы к человеку» скилла agentio-youtrack-workflow: варианты, рекомендация, Stage для возврата `Backlog`), затем `php artisan agentio:yt release <IDEA> --state=Blocked` и остановись.

## Итог (последнее сообщение)

```
PLAN <IDEA>: DONE | BLOCKED | PARKED
Эпики: {{project}}-.. (Ready), ...
Первая волна задач: {{project}}-.., {{project}}-..
Статьи: {{project}}-A-.., ...
```

У отложенной идеи вместо эпиков и задач — `Статья идеи: {{project}}-A-..` и затрагиваемые статьи «Системной аналитики».
