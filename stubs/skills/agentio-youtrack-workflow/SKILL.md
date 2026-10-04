---
name: agentio-youtrack-workflow
description: Правила работы агентов с YouTrack в проекте {{project}} — иерархия IDEA/EPIC/STORY/TASK, статусы (поле Stage), зависимости и правило готовности, захват задач, комментарии [AGENT:*], поисковые запросы, чтение контекста и карта базы знаний. Все операции с YouTrack идут через MCP-сервер youtrack. Применять перед любым чтением, созданием или изменением задач и статей YouTrack, перед началом или возобновлением работы над задачей и при написании комментариев агента.
user-invocable: false
---

# YouTrack workflow (проект `{{project}}`)

YouTrack — единственный источник правды: задачи, связи, контекст работы агентов и база знаний. Локальные файлы-заметки для контекста не используются. Работа должна возобновляться с любого места **только по данным YouTrack**.

## Инструменты

- **MCP-сервер `youtrack`** (`mcp__youtrack__*`) — единственный канал работы с YouTrack: задачи, комментарии, поля, метки, связи, статьи базы знаний. Как вызывать каждый инструмент (постраничное чтение, лимиты, смена Stage) — [references/mcp-tools.md](references/mcp-tools.md).
- **`php artisan agentio:yt <действие>`** — детерминированные вычисления процесса поверх того же MCP-сервера: дерево эпика, готовность задач, валидация графа, захват и освобождение, дерево базы знаний. Справка: `php artisan agentio:yt help`.
- Не обращайся к REST API YouTrack (curl, http-клиенты) и не ищи токен: MCP-сервер и `agentio:yt` подключаются сами.

Если правило ниже и поведение `agentio:yt` расходятся, прав `agentio:yt`. Расхождение оформи комментарием `[AGENT:DECISION]` в задаче.

## 1. Иерархия

| Уровень | Префикс summary | Поле `Type` | Родитель (`subtask of`) | Смысл |
|---|---|---|---|---|
| Идея | `[IDEA]` | `Idea` (или метка `idea`) | — | Сырой запрос человека. Вход для `/agentio-plan`. |
| Эпик | `[EPIC]` | `Epic` | — (с идеей связан через `relates to`) | Законченная ценность для пользователя. Один оркестратор, один worktree, одна ветка `epic/<ID>-<slug>`. |
| История | `[STORY]` | `Story` | EPIC | Один пользовательский сценарий с критериями приёмки Given/When/Then. |
| Задача | `[TASK]` | `Task` | STORY | Атомарное изменение: один субагент, один набор коммитов, выполнима без уточнений. Если в описании нужно слово «и» — дели дальше. |

Префикс в summary и значение `Type` обязательны и должны совпадать.

**Создание задачи — один вызов:** `mcp__youtrack__create_issue(project="{{project}}", summary="[TASK] …", description=…, parentIssue=<родитель>, customFields={"Type": "Task", "Stage": "Ready"})`. `parentIssue` — для STORY и TASK; начальный `Stage` — только `Backlog`, `Analysis` или `Ready`. Шаблоны описаний — [references/issue-templates.md](references/issue-templates.md). Метка `idea` для идеи — `manage_issue_tags`.

## 2. Связи

- `subtask of` / `parent for` — только иерархия (её ставит `parentIssue` при создании).
- `depends on` / `is required for` — зависимости между задачами любого уровня. `link_issues(targetIssueId=A, linkType="depends on", issueToLinkId=B)` означает «A зависит от B».
- `relates to` — идея ↔ эпики, задача ↔ смежная задача.
- **Циклы запрещены.** После расстановки зависимостей выполни `php artisan agentio:yt validate <EPIC|IDEA>`.
- Задачи, которые трогают одни и те же файлы или миграции, параллельно не ведутся: связывай их через `depends on`, даже если логической зависимости нет.
- Зависимости наследуются: задача ждёт и свои зависимости, и зависимости своих STORY и EPIC.

Статьи базы знаний связываются ссылкой в тексте (`{{kb.architecture.overview}}`) в описании задачи. Статья в ответ ссылается на эпик.

## 3. Статусы (поле `Stage`)

`Backlog → Analysis → Ready → In Progress → Review → Done`, плюс `Blocked` из любого состояния. Других полей статуса нет: доска YouTrack строится по `Stage`.

| Stage | IDEA | EPIC / STORY / TASK |
|---|---|---|
| Backlog | ждёт `/agentio-plan` | заведена, описание неполное |
| Analysis | идёт планирование (захвачена) | идёт аналитика / архитектура / декомпозиция |
| Ready | — | полностью описана, можно брать при выполнении правила готовности |
| In Progress | — | захвачена агентом (`agent-claimed`) |
| Review | — | TASK: не используется. STORY: прошла ревью. EPIC: ветка готова, ждёт приёмки человеком |
| Blocked | ждёт ответа человека | ждёт человека; причина в последнем `[AGENT:BLOCKED]` |
| Done | план создан | TASK: реализована и закоммичена. STORY / EPIC: принято (после слияния в `{{base_branch}}`) |

**Смена статуса:** сначала комментарий `[AGENT:*]` о причине (`add_issue_comment`), затем `mcp__youtrack__update_issue(issueId=<ID>, customFields={"Stage": "Ready"})`. Захват и освобождение — только `agentio:yt claim` / `release` (раздел 5): они ведут и метку `agent-claimed`.

Кто двигает статусы:
- project-manager: идея Backlog→Analysis→Done/Blocked; эпики, истории и задачи Backlog→Analysis→Ready; закрывает STORY и EPIC (→Done) после приёмки.
- оркестратор `/agentio-work-epic`: EPIC Ready→In Progress→Review; STORY Ready→In Progress→Review.
- разработчик задачи: TASK Ready→In Progress→Done.
- любой агент: →Blocked с `[AGENT:BLOCKED]`.
- человек: Blocked→(прежний статус) после ответа; EPIC Review→Done после слияния.

## 4. Правило готовности

**TASK** можно брать, когда одновременно:
1. `Stage: Ready`;
2. все задачи, от которых она зависит (включая унаследованные от STORY и EPIC), в `Done`. Исключение: зависимость **внутри того же эпика** в состоянии `Review` тоже считается выполненной — её код уже в ветке эпика;
3. нет метки `agent-claimed`.

**EPIC** можно брать, когда он `Ready`, его зависимости-эпики в `Done`, нет `agent-claimed` и хотя бы одна его TASK готова по правилу выше.

Проверка — только `agentio:yt`, язык запросов YouTrack не умеет проверять состояние зависимостей: `php artisan agentio:yt ready-epics`, `php artisan agentio:yt ready-tasks <EPIC>`, `php artisan agentio:yt tree <EPIC>`. Сохранённые поиски «{{project}}: готовые эпики», «{{project}}: готовые задачи» дают только **кандидатов**.

## 5. Захват

Работать над задачей без захвата **запрещено**:

```bash
php artisan agentio:yt claim {{project}}-42 --plan="<кратко план>"
```

Команда делает ровно то, что требует процесс:
1. Пишет комментарий `[AGENT:START]` с `owner`, веткой и worktree.
2. Ставит метку `agent-claimed` и `Stage: In Progress`.
3. Перечитывает комментарии и проверяет, что активный захват твой. Владелец — самый ранний `[AGENT:START]` после последнего `[AGENT:DONE|BLOCKED|RELEASE]`.

Ветка и worktree берутся из текущего git-каталога, `owner` — `<hostname>:<worktree>`. Субагенты, работающие в одном worktree, добавляют суффикс: `--as=<TASK-ID>`. Коды выхода: `0` — `CLAIMED` или `RESUMED` (продолжаем свой захват); `3` — `LOST` (захват чужой), задачу не трогай.

Освобождение — после итогового комментария: `php artisan agentio:yt release {{project}}-42 --state=Done` (или `Review`, `Blocked`, `Ready`): ставит Stage и снимает `agent-claimed`.

**Bash в автономном режиме.** Агенты работают в `--permission-mode dontAsk` с белым списком команд. Команды с подстановками `$(...)`, обратными кавычками и переменными `$VAR` не совпадают с правилами и **отклоняются**: пиши значения буквально, не оборачивай в `sh -c`. Поэтому текст с Markdown пиши через MCP (`add_issue_comment`), а не опцией `--comment`.

## 6. Комментарии агентов

Контекст работы хранится **только** в комментариях по шаблонам из [references/comment-templates.md](references/comment-templates.md). Первая строка — маркер:

- `[AGENT:START]` — план, затрагиваемые файлы, допущения (первый пишет `claim`, план дописывается следующим `[AGENT:START]`, если не поместился в `--plan`).
- `[AGENT:DECISION]` — принятое решение и отвергнутые варианты.
- `[AGENT:BLOCKED]` — что мешает и что нужно от человека; затем `release <ID> --state=Blocked` (задача захвачена тобой) или смена Stage на `Blocked`.
- `[AGENT:DONE]` — что сделано, коммиты, как проверить, что осталось.
- `[AGENT:RELEASE]` — служебный: снять захват без завершения.

Пиши комментарий в момент события, а не в конце сессии: обрыв может случиться в любой момент.

## 7. Чтение контекста перед началом или возобновлением

Обязательно, до любых изменений:
1. `get_issue(<ID>)` — описание, поля, метки, `parentIssue`; затем `get_issue` по каждому родителю (STORY, EPIC) — их описания.
2. **Все** комментарии задачи и родителей: `get_issue_comments` постранично до конца (раздел «Комментарии» в [references/mcp-tools.md](references/mcp-tools.md)); нужны все `[AGENT:*]`.
3. Дерево и зависимости: `php artisan agentio:yt tree <EPIC>` (статусы, `waits:` — незакрытые зависимости, `[READY]`, `[CLAIMED]`).
4. Статьи базы знаний, упомянутые в задаче и родителях (`get_article`, длинные — до конца).
5. Если есть `[AGENT:DONE]` прошлой попытки — проверь в git, что коммиты есть (`git log --oneline --grep=<ID>`), и продолжай с пункта «что осталось».

## 8. Поисковые запросы (`search_issues`)

| Назначение | Запрос |
|---|---|
| Идеи без плана | `project: {{project}} tag: idea Stage: Backlog tag: -{agent-claimed}` (и то же с `Type: Idea`) |
| Готовые эпики (кандидаты) | `project: {{project}} Type: Epic Stage: Ready tag: -{agent-claimed}` |
| Готовые задачи (кандидаты) | `project: {{project}} Type: Task Stage: Ready tag: -{agent-claimed}` |
| Заблокированные (причина в `[AGENT:BLOCKED]`) | `project: {{project}} Stage: Blocked` |
| В работе у агентов | `project: {{project}} tag: {agent-claimed}` |
| Эпики на приёмке | `project: {{project}} Type: Epic Stage: Review` |
| Дети задачи | `subtask of: {{project}}-12` |
| Родитель | `parent for: {{project}}-12` |
| От чего зависит задача / кого она блокирует | `is required for: {{project}}-12` / `depends on: {{project}}-12` |
| Эпики идеи | `project: {{project}} Type: Epic relates to: {{project}}-1` |

Запросы сохранены в YouTrack с префиксом «{{project}}:». Скобки и `or` внутри запросов YouTrack не поддерживает.

## 9. Карта базы знаний (проект {{project}})

| Статья | ID |
|---|---|
| Обзор продукта | {{kb.overview}} |
| Системная аналитика (корень, карта модулей) · Общие требования | {{kb.analysis}} · {{kb.analysis.common}} |
| Модули и их фичи (требования, бизнес-правила, логическая модель данных) | дочерние статьи корня аналитики: `php artisan agentio:yt kb-tree {{kb.analysis}}` |
| Архитектура (корень) · обзор · модель данных | {{kb.architecture}} · {{kb.architecture.overview}} · {{kb.architecture.data_model}} |
| ADR (корень) · ADR-001 Базовые архитектурные решения | {{kb.adr}} · {{kb.adr.001}} |
| Процесс разработки | {{kb.process}} |
| Руководство по автоматизации (для человека) | {{kb.guide}} |
| Глоссарий | {{kb.glossary}} |

Дерево статей с ID — `php artisan agentio:yt kb-tree [<ID статьи>] [--depth=N] [--json]`; ID статей не угадывай. Новые статьи создаются сразу под родителем: `create_article(project="{{project}}", summary=…, content=…, parentArticle=<ID родителя>)` — модули и фичи аналитики (скилл agentio-system-analyst), проекты эпиков и новые ADR (скилл agentio-laravel-architect). Статью не на своём месте переносит `update_article(articleId, parentArticleId=<ID>)`.

## Чек-лист самопроверки

- [ ] Перед работой прочитан полный контекст (раздел 7), все комментарии — до последней страницы.
- [ ] Задача захвачена через `agentio:yt claim`, код выхода 0.
- [ ] У каждой созданной задачи есть префикс, `Type`, `Stage` и родитель (кроме EPIC и IDEA).
- [ ] Каждая смена Stage сопровождается комментарием `[AGENT:*]`.
- [ ] Зависимости не образуют циклов (`agentio:yt validate`).
- [ ] При завершении: `[AGENT:DONE]`, затем `agentio:yt release` с правильным статусом; метка `agent-claimed` снята.
