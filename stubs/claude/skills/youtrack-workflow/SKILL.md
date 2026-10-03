---
name: youtrack-workflow
description: Конвенции работы с YouTrack (проект {{project}}) для всех агентов — иерархия EPIC/STORY/TASK, статусы, зависимости, правило готовности, захват задач, шаблоны комментариев [AGENT:*], поисковые запросы и чтение контекста. Применять ВСЕГДА перед любым чтением, созданием или изменением задач в YouTrack, перед началом или возобновлением работы над задачей и при написании комментариев агента.
when_to_use: Любая работа с задачами {{project}} — планирование, взятие задачи, продолжение после обрыва, смена статуса, блокировка, завершение.
---

# YouTrack workflow (проект `{{project}}`)

YouTrack — единственный источник правды: задачи, связи, контекст работы агентов и база знаний. Локальные файлы-заметки для контекста не используются. Работа должна возобновляться с любого места **только по данным YouTrack**.

Инструменты:
- MCP `youtrack` (`mcp__youtrack__*`) — чтение и запись задач, комментариев, связей, меток, статей.
- `php scripts/yt.php <command>` — детерминированные вычисления: готовность, дерево эпика, валидация графа, захват и освобождение, **смена статуса** (`set-state`), полный контекст, дерево базы знаний (`kb-tree`). Нужны `YOUTRACK_URL` и `YOUTRACK_TOKEN` в окружении. Справка: `php scripts/yt.php help`.

Если правило ниже и поведение `scripts/yt.php` расходятся, прав скрипт. Расхождение оформи комментарием `[AGENT:DECISION]` в задаче.

## 1. Иерархия

| Уровень | Префикс summary | Поле `Type` | Родитель (`subtask of`) | Смысл |
|---|---|---|---|---|
| Идея | `[IDEA]` | `Idea` + метка `idea` | — | Сырой запрос человека. Вход для `/plan`. |
| Эпик | `[EPIC]` | `Epic` | — (с идеей связан через `relates to`) | Законченная ценность для пользователя. Один оркестратор, один worktree, одна ветка `epic/<ID>-<slug>`. |
| История | `[STORY]` | `Story` | EPIC | Один пользовательский сценарий с критериями приёмки Given/When/Then. |
| Задача | `[TASK]` | `Task` | STORY | Атомарное изменение: один субагент, один набор коммитов, выполнима без уточнений. Если в описании нужно слово «и» — дели дальше. |

Префикс в summary и значение `Type` обязательны и должны совпадать.

**Создание задачи — один вызов:** `mcp__youtrack__create_issue(project="{{project}}", summary="[TASK] …", description=…, parentIssue=<родитель>, customFields={"Type": "Task", "State": "Ready", "Stage": "Backlog"})`. `parentIssue` — для STORY и TASK; начальный `State` — только `Backlog`, `Analysis` или `Ready` (им всем соответствует `Stage: Backlog`, см. раздел 3). Метка `idea` для идеи — `manage_issue_tags`. Все дальнейшие смены статуса — только `php scripts/yt.php set-state` (раздел 3).

## 2. Связи

- `subtask of` / `parent for` (тип `Subtask`) — только иерархия.
- `depends on` / `is required for` (тип `Depend`) — зависимости между задачами любого уровня. `link_issues(targetIssueId=A, linkType="depends on", issueToLinkId=B)` означает «A зависит от B».
- `relates to` — идея ↔ эпики, задача ↔ смежная задача.
- **Циклы запрещены.** После расстановки зависимостей выполни `php scripts/yt.php validate <EPIC|IDEA>`.
- Задачи, которые трогают одни и те же файлы или миграции, параллельно не ведутся: связывай их через `depends on`, даже если логической зависимости нет.
- Зависимости наследуются: задача ждёт и свои зависимости, и зависимости своих STORY и EPIC.

Статьи базы знаний связываются ссылкой в тексте (`{{kb.architecture.overview}}`) в описании задачи. Статья в ответ ссылается на эпик.

## 3. Статусы (поле `State`)

`Backlog → Analysis → Ready → In Progress → Review → Done`, плюс `Blocked` из любого состояния.

| State | IDEA | EPIC / STORY / TASK |
|---|---|---|
| Backlog | ждёт `/plan` | заведена, описание неполное |
| Analysis | идёт `/plan` (захвачена) | идёт аналитика / архитектура / декомпозиция |
| Ready | — | полностью описана, можно брать при выполнении правила готовности |
| In Progress | — | захвачена агентом (`agent-claimed`) |
| Review | — | TASK: не используется. STORY: прошла `story-reviewer`. EPIC: ветка готова, ждёт приёмки человеком |
| Blocked | ждёт ответа человека | ждёт человека; причина в последнем `[AGENT:BLOCKED]` |
| Done | план создан | TASK: реализована и закоммичена. STORY / EPIC: принято (после слияния в `{{base_branch}}`) |

**Смена статуса — только скриптом** (он ставит State и Stage одним запросом):

```bash
php scripts/yt.php set-state {{project}}-42 Ready
php scripts/yt.php set-state {{project}}-42 Blocked --comment="[AGENT:BLOCKED] ..."   # комментарий пишется до смены статуса
```

Комментарий с Markdown-разметкой (обратные кавычки, `$`) в `--comment` в автономном режиме не пройдёт (раздел 5, «Bash в автономном режиме»): пиши его через `mcp__youtrack__add_issue_comment`, затем вызывай `set-state` без `--comment`.

Захват и освобождение — `claim` и `release --state=…` (раздел 5), они тоже синхронизируют Stage. **Не меняй State через `update_issue` / `customFields`**: Stage разъедется с State до ближайшего `sync-stage`. Неизвестный State скрипт отклоняет (код 1).

### Stage — колонки Kanban-доски

Поле `Stage` (Backlog, Develop, Review, Test, Staging, Done) питает колонки Kanban-доски проекта. Оно **выводится из State автоматически**, вручную его не меняй:

| State | Stage |
|---|---|
| Backlog, Analysis, Ready, Blocked | Backlog |
| In Progress | Develop |
| Review | Review |
| Done | Done |

- `set-state`, `claim` и `release` ставят Stage вместе со State. Если у проекта нет поля Stage, скрипт работает только со State.
- `php scripts/yt.php sync-stage [<ID>...] [--dry-run]` исправляет расхождения (без аргументов — по всем задачам проекта). `scripts/agent-loop.sh` вызывает его в начале каждого прохода.
- Значения `Test` и `Staging` процессом агентов не используются.
- **Ловушка поиска:** запрос `State: Backlog` находит и задачи со `Stage = Backlog` (у полей одноимённые значения; то же для Review и Done). Поэтому выборки по State делай через `yt.php` (`ideas`, `status`, `blocked`, `ready-*` проверяют State на клиенте), а результаты поисков YouTrack считай кандидатами.

Кто двигает статусы:
- `project-manager`: Idea Backlog→Analysis→Done/Blocked; эпики, истории и задачи Backlog→Analysis→Ready; закрывает STORY и EPIC (→Done) после приёмки.
- оркестратор `/work-epic`: EPIC Ready→In Progress→Review; STORY Ready→In Progress→Review.
- `task-developer`: TASK Ready→In Progress→Done.
- любой агент: →Blocked с `[AGENT:BLOCKED]`.
- человек: Blocked→(прежний статус) после ответа; EPIC Review→Done после слияния.

Агенты меняют статус только через `set-state` / `claim` / `release`; человек — в интерфейсе YouTrack (State; Stage подтянет `sync-stage`) или той же командой `set-state`.

## 4. Правило готовности (ключевое)

**TASK** можно брать, когда одновременно:
1. `State: Ready`;
2. все задачи, от которых она зависит (включая унаследованные от STORY и EPIC), в `Done`. Исключение: зависимость **внутри того же эпика** в состоянии `Review` тоже считается выполненной, потому что её код уже лежит в ветке эпика;
3. нет метки `agent-claimed`.

**EPIC** можно брать, когда он `Ready`, его зависимости-эпики в `Done`, нет `agent-claimed` и хотя бы одна его TASK готова по правилу выше.

Проверка: `php scripts/yt.php ready-epics`, `php scripts/yt.php ready-tasks <EPIC>`, `php scripts/yt.php tree <EPIC>`. Сохранённые поиски в YouTrack («{{project}}: готовые эпики», «{{project}}: готовые задачи») дают **кандидатов**: язык запросов YouTrack не умеет проверять состояние зависимостей.

## 5. Захват

Работать над задачей без захвата **запрещено**. Захват:

```bash
php scripts/yt.php claim {{project}}-42 --plan="<кратко план>"
```

Скрипт делает ровно то, что требует процесс:
1. Пишет комментарий `[AGENT:START]` с `owner`, веткой и worktree.
2. Ставит метку `agent-claimed` и статус `In Progress`.
3. Перечитывает задачу и проверяет, что активный захват твой. Владелец — самый ранний `[AGENT:START]` после последнего `[AGENT:DONE|BLOCKED|RELEASE]`.

Ветка и worktree по умолчанию берутся из текущего git-каталога, `owner` — `<hostname>:<worktree>`. Субагенты, работающие в одном worktree, добавляют суффикс: `--as=<TASK-ID>` (owner `<hostname>:<worktree>#<TASK-ID>`). Коды выхода: `0` — захвачено (`CLAIMED`) или продолжаем свой захват (`RESUMED`); `3` — захват чужой (`LOST`), задачу не трогай.

**Bash в автономном режиме.** Агенты работают в `--permission-mode dontAsk` с белым списком команд. Команды с подстановками `$(...)`, обратными кавычками и переменными `$VAR` не совпадают с правилами и **отклоняются**. Пиши значения буквально, разбивай сложное на отдельные простые команды и не оборачивай в `sh -c`.

Освобождение: `php scripts/yt.php release {{project}}-42 --state=Done` (или `Review`, `Blocked`, `Ready`) — после того как записан итоговый комментарий. Для смены статуса без снятия захвата (например, STORY → Review, идея → Analysis) — `php scripts/yt.php set-state <ID> <State>`.

## 6. Комментарии агентов

Контекст работы хранится **только** в комментариях по шаблонам из [comment-templates.md](comment-templates.md). Первая строка комментария — маркер:

- `[AGENT:START]` — план, затрагиваемые файлы, допущения (первый пишет `claim`, план дописывается следующим `[AGENT:START]`, если не поместился в `--plan`).
- `[AGENT:DECISION]` — принятое решение и отвергнутые варианты.
- `[AGENT:BLOCKED]` — что мешает и что нужно от человека. Статус → `Blocked` (`release <ID> --state=Blocked`, если задача захвачена тобой, иначе `set-state <ID> Blocked`).
- `[AGENT:DONE]` — что сделано, коммиты, как проверить, что осталось.
- `[AGENT:RELEASE]` — служебный: снять захват без завершения (например, задачу вернули в Ready).

Пиши комментарий в момент события, а не в конце сессии: обрыв может случиться в любой момент.

## 7. Чтение контекста перед началом или возобновлением

Обязательно, до любых изменений:
1. `php scripts/yt.php context <ID>` — задача, описание, родители (STORY и EPIC) с описаниями, дети, незакрытые зависимости, владелец захвата и **все** `[AGENT:*]` комментарии задачи и родителей. Если скрипт недоступен: `get_issue` по задаче и каждому родителю плюс `get_issue_comments` постранично до конца.
2. Статьи базы знаний, упомянутые в задаче и родителях (`mcp__youtrack__get_article`).
3. Если есть `[AGENT:DONE]` от прошлой попытки — проверь в git, что указанные коммиты есть (`git log --oneline --grep=<ID>`), и продолжай с пункта «что осталось».

## 8. Поисковые запросы

| Назначение | Запрос |
|---|---|
| Идеи без плана (кандидаты: найдёт и идеи в Analysis/Blocked со `Stage: Backlog`; точно — `yt.php ideas`) | `project: {{project}} tag: idea State: Backlog tag: -{agent-claimed}` |
| Готовые эпики (кандидаты) | `project: {{project}} Type: Epic State: Ready tag: -{agent-claimed}` |
| Готовые задачи (кандидаты) | `project: {{project}} Type: Task State: Ready tag: -{agent-claimed}` |
| Заблокированные (причина в `[AGENT:BLOCKED]`) | `project: {{project}} State: Blocked` |
| В работе у агентов | `project: {{project}} tag: {agent-claimed}` |
| Эпики на приёмке | `project: {{project}} Type: Epic State: Review` |
| Дети задачи | `subtask of: {{project}}-12` |
| От чего зависит / кого блокирует | `depends on: {{project}}-12` / `is required for: {{project}}-12` |

Все запросы сохранены в YouTrack с префиксом «{{project}}:». Скобки и `or` внутри запросов YouTrack не поддерживает. Запросы с `State: Backlog`, `State: Review`, `State: Done` захватывают и задачи с таким же значением `Stage` (раздел 3) — перепроверяй State в результате.

## 9. Карта базы знаний (проект {{project}})

| Статья | ID |
|---|---|
| Обзор продукта | {{kb.overview}} |
| Системная аналитика (корень, карта модулей) · Общие требования | {{kb.analysis}} · {{kb.analysis.common}} |
| Модули и их фичи (требования, бизнес-правила, логическая модель данных) | дочерние статьи корня аналитики: `php scripts/yt.php kb-tree {{kb.analysis}}` |
| Архитектура (корень) · обзор · модель данных | {{kb.architecture}} · {{kb.architecture.overview}} · {{kb.architecture.data_model}} |
| ADR (корень) · ADR-001 Базовые архитектурные решения | {{kb.adr}} · {{kb.adr.001}} |
| Процесс разработки | {{kb.process}} |
| Руководство по автоматизации (для человека; копия — `docs/AUTONOMOUS_WORKFLOW.md`) | {{kb.guide}} |
| Глоссарий | {{kb.glossary}} |

Дерево статей с ID — `php scripts/yt.php kb-tree [<ID статьи>] [--depth=N] [--json]` (без аргумента — вся база знаний проекта); ID статей не угадывай. Новые статьи создаются сразу под родителем: `create_article(project="{{project}}", summary=…, content=…, parentArticle=<ID родителя>)` — модули и фичи аналитики (скилл `system-analyst`), проекты эпиков и новые ADR (скилл `laravel-architect`). Статью не на своём месте переносит `update_article(articleId, parentArticleId=<ID>)`.

## Чек-лист самопроверки

- [ ] Перед работой прочитан полный контекст (раздел 7).
- [ ] Задача захвачена через `claim`, код выхода 0.
- [ ] У каждой созданной задачи есть префикс, `Type`, `State`, `Stage: Backlog` и родитель (кроме EPIC и IDEA).
- [ ] Статусы менялись только через `set-state` / `claim` / `release`, не через `update_issue`.
- [ ] Зависимости не образуют циклов (`validate`).
- [ ] Каждое значимое событие оставило комментарий `[AGENT:*]` по шаблону.
- [ ] При завершении: `[AGENT:DONE]`, затем `release` с правильным статусом. Метка `agent-claimed` снята.
