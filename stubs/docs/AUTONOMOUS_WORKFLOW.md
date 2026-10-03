# Руководство по автоматизации разработки (YouTrack + Claude Code)

Человек заводит идею в YouTrack и принимает результат. Всё остальное делают агенты Claude Code: требования, системную аналитику, архитектуру, задачи, код, тесты и ревью.

- Копия этого руководства в базе знаний YouTrack — статья **{{kb.guide}} «Руководство по автоматизации»** (дочерняя к {{kb.process}}).
- Правила процесса: иерархия, статусы, готовность, захват, комментарии. Для людей — статья **{{kb.process}} «Процесс разработки»**, для агентов — скилл `.claude/skills/youtrack-workflow/SKILL.md`. Если они расходятся, прав `scripts/yt.php`.
- Краткие правила для агентов — в `CLAUDE.md` (блок между маркерами `<!-- agentio:start -->` и `<!-- agentio:end -->`).
- Всё это ставит в проект пакет `obrazmisli/agentio` командой `php artisan agentio:install`; повторная установка обновляет файлы, которые вы не меняли. Цикл запускается `php artisan agentio:run`, сводка — `php artisan agentio:status`, наблюдение в браузере — страница `/agentio`.

**Содержание:**
1. Обзор
2. Требования и первичная настройка
3. Работа с задачами
4. Запуск и остановка
5. Наблюдение
6. Ревью и приёмка эпика
7. Blocked, сбои и частые проблемы
8. Безопасность
9. Шпаргалка

---

## 1. Обзор

### 1.1. Что делает система

YouTrack (проект `{{project}}`, инстанс из `YOUTRACK_URL`) — единственный источник правды. В нём лежат задачи, их связи, база знаний и весь контекст работы агентов (комментарии `[AGENT:*]`). Агент может продолжить работу с любого места только по данным YouTrack.

Цикл `scripts/agent-loop.sh` (запуск — `php artisan agentio:run`) раз в 5 минут делает три вещи:
- находит идеи без плана и планирует их (`/plan`);
- находит готовые эпики и для каждого запускает отдельную сессию Claude Code (`/work-epic`) в собственном git worktree и на собственной ветке `epic/<ID>-<slug>`;
- подбирает завершившиеся сессии.

Результат — ветка эпика, которую проверяет и сливает человек.

### 1.2. Роли

| Роль | Чем реализована | Что делает | Какие статусы двигает |
|---|---|---|---|
| Владелец продукта (человек) | YouTrack UI, терминал | Заводит идеи, отвечает на вопросы `[AGENT:BLOCKED]`, принимает и сливает эпики | Blocked → прежний статус; EPIC/STORY → Done после слияния |
| Менеджер проекта (PM) | скилл `project-manager` (в составе `/plan`) | Пишет требования к идее, создаёт EPIC → STORY → TASK, расставляет зависимости, переводит в Ready, разбирает блокировки | Idea: Backlog → Analysis → Done/Blocked; EPIC/STORY/TASK: Analysis → Ready |
| Системный аналитик | скилл `system-analyst` | Анализирует по 7 слоям, обновляет статьи «Системная аналитика», ищет противоречия, пишет сводку влияния | → Blocked при противоречии |
| Архитектор | скилл `laravel-architect` | Проектирует эпик (данные, маршруты, интерфейс, очереди), пишет ADR, задаёт порядок реализации и параллельные группы | — |
| Оркестратор эпика | скилл-команда `/work-epic` | Захватывает эпик, раздаёт задачи разработчикам (независимые — параллельно), запускает ревью историй, прогоняет полный набор тестов | EPIC: Ready → In Progress → Review; STORY: Ready → In Progress → Review |
| Разработчик | субагент `task-developer` | Реализует одну TASK: код, тесты, линтеры, коммит, `[AGENT:DONE]` | TASK: Ready → In Progress → Done |
| Ревьюер | субагент `story-reviewer` | Проверяет STORY по критериям приёмки и ADR; замечания оформляет новыми TASK | STORY → Blocked на 3-м раунде замечаний |

### 1.3. Поток работы

```
[IDEA] (Backlog)
   │  /plan — project-manager → system-analyst → laravel-architect → декомпозиция
   ▼
[EPIC] → [STORY] → [TASK]  (Ready, с зависимостями)        идея → Done
   │  scripts/agent-loop.sh: worktree ../worktrees/<EPIC>, ветка epic/<EPIC>-<slug>
   ▼
/work-epic: task-developer по каждой TASK (параллельно, если файлы не пересекаются)
   │        story-reviewer по каждой STORY (APPROVED или новые TASK-замечания)
   ▼
полный прогон тестов → [AGENT:DONE] → EPIC: Review
   │
   ▼
человек: проверка → merge в {{base_branch}} → EPIC и STORY: Done
```

### 1.4. Иерархия задач

| Уровень | Префикс summary | Поле `Type` | Родитель (`subtask of`) | Смысл |
|---|---|---|---|---|
| Идея | `[IDEA]` | `Idea` + метка `idea` | — | Сырой запрос человека |
| Эпик | `[EPIC]` | `Epic` | — (с идеей связан `relates to`) | Законченная ценность. Один оркестратор, один worktree, одна ветка |
| История | `[STORY]` | `Story` | EPIC | Один сценарий с критериями приёмки Given/When/Then |
| Задача | `[TASK]` | `Task` | STORY | Атомарное изменение для одного субагента |

Связи: `subtask of` — иерархия; `depends on` / `is required for` — зависимости (циклы запрещены); `relates to` — идея ↔ эпики, смежные задачи.

### 1.5. Статусы (State) и Kanban-доска (Stage)

Процесс ведётся полем **State**: `Backlog → Analysis → Ready → In Progress → Review → Done`, плюс `Blocked` из любого состояния.

| State | IDEA | EPIC / STORY / TASK |
|---|---|---|
| Backlog | ждёт `/plan` | заведена, описание неполное |
| Analysis | идёт планирование | идёт аналитика, архитектура, декомпозиция |
| Ready | — | полностью описана, её можно брать (если выполнено правило готовности) |
| In Progress | — | захвачена агентом (метка `agent-claimed`) |
| Review | — | STORY прошла ревью; EPIC — ветка готова и ждёт человека |
| Blocked | ждёт ответа человека | ждёт человека, причина в последнем `[AGENT:BLOCKED]` |
| Done | план создан | TASK реализована и закоммичена; STORY и EPIC приняты после слияния |

**Правило готовности.** TASK берётся в работу, когда выполнены все три условия:
- она в `Ready`;
- все её зависимости, включая унаследованные от STORY и EPIC, в `Done` (зависимости внутри того же эпика достаточно быть в `Review`);
- на ней нет метки `agent-claimed`.

EPIC берётся, когда он в `Ready`, его зависимости-эпики в `Done`, метки `agent-claimed` нет и хотя бы одна его TASK готова. Готовность вычисляет `php scripts/yt.php ready-epics` / `ready-tasks <EPIC>`. Сохранённые поиски YouTrack дают только кандидатов.

Поле **Stage** строит колонки Kanban-доски проекта (доску с колонками по Stage создайте в YouTrack вручную, см. 2.6): Backlog, Develop, Review, Test, Staging, Done. Stage **выводится из State автоматически**, вручную его не меняйте:

| State | Stage (колонка доски) |
|---|---|
| Backlog, Analysis, Ready, Blocked | Backlog |
| In Progress | Develop |
| Review | Review |
| Done | Done |

- `php scripts/yt.php set-state`, `claim` и `release` ставят State и Stage одним запросом.
- Каждый проход цикла начинается с `php scripts/yt.php sync-stage`. Команда исправляет расхождения, например после того как человек сменил State в интерфейсе YouTrack.
- Колонки Test и Staging агенты не используют.
- **Ловушка поиска.** Запрос `State: Backlog` находит и задачи со `Stage = Backlog`, потому что у полей одноимённые значения. То же с Review и Done. Точные списки дают команды `yt.php` (`ideas`, `status`, `blocked`): они проверяют State сами.

### 1.6. Где что лежит

| Путь | Назначение |
|---|---|
| `php artisan agentio:install` | Установка и обновление всего перечисленного ниже; `--youtrack` — настройка проекта YouTrack |
| `php artisan agentio:run` | Запуск цикла с настройками из `config/agentio.php` и `.env` (обёртка над `scripts/agent-loop.sh`) |
| `php artisan agentio:status` | Сводка: цикл, живые сессии, счётчики и блокировки в YouTrack |
| `/agentio` | Веб-страница наблюдения за процессом (маршрут пакета) |
| `config/agentio.php`, `.agentio.json` | Настройки пакета; проект YouTrack и ID статей базы знаний, найденные при установке |
| `scripts/agent-loop.sh` | Автономный цикл |
| `scripts/yt.php` | REST-помощник YouTrack: готовность, дерево, захват, статусы, контекст (`php scripts/yt.php help`) |
| `scripts/epic-worktree.sh` | Создать и подготовить или удалить worktree эпика |
| `scripts/agent-commit.sh` | Коммит только перечисленных файлов под блокировкой (для параллельных субагентов) |
| `scripts/run-tests.sh` | Запуск тестов с выводом в файл и уборкой процессов браузерных тестов; команды настраиваются (`AGENTIO_TEST_COMMAND`, `AGENTIO_FULL_TEST_COMMAND`) |
| `scripts/agent-log.php` | Чтение логов сессий агентов в человекочитаемом виде |
| `.claude/skills/*`, `.claude/agents/*` | Роли (скиллы) и субагенты |
| `.claude/settings.json` | Белый и чёрный списки команд, хук `guard-bash` |
| `.claude/agent-settings.json` | Дополнительные запреты для headless-агентов |
| `.claude/agents-mcp.json` | MCP-серверы для headless-агентов (YouTrack и Laravel Boost, если он установлен) |
| `.claude/hooks/guard-bash.php` | Второй рубеж защиты для Bash-команд |
| `storage/logs/agents/` | Логи цикла и сессий: `loop.log`, `loop.pid`, `<EPIC>.log`, `<EPIC>.pid`, `<EPIC>.restarts`, `<EPIC>.setup.log`, `plan-<IDEA>.log`, `plan-<IDEA>.pid` |
| `../worktrees/<EPIC>` | Рабочие копии эпиков |

---

## 2. Требования и первичная настройка

### 2.1. Окружение машины

| Что | Проверка |
|---|---|
| PHP с расширениями `curl`, `mbstring`, `posix` (желательно `intl` и `pcntl`) | `php -m` |
| composer, git | `composer -V && git --version` |
| `setsid` и `flock` (util-linux; на macOS — `brew install util-linux`) | `command -v setsid flock` |
| bun или npm — если у проекта есть фронтенд-сборка | `bun -v` / `npm -v` |
| Claude Code, вход выполнен | `claude --version`, `claude -p "ok"` |
| Всё, что нужно самому проекту для тестов (БД, Redis, браузеры для браузерных тестов и т. п.) | — |

`php artisan agentio:install` проверяет эти предусловия и печатает, чего не хватает. `scripts/agent-loop.sh` при старте проверяет наличие `php`, `git`, `composer`, `setsid` и `claude` (или того, что задано в `CLAUDE_BIN`). Менеджер JS-пакетов `scripts/epic-worktree.sh` выбирает по lock-файлу (`bun.lock` → bun, `package-lock.json` → npm); без lock-файла или без скрипта `build` в `package.json` фронтенд-шаги пропускаются.

`scripts/run-tests.sh` сам включает `zend.assertions=1` через `scripts/php/testing.ini`: многие системные php.ini выключают `assert()`, и строгий гейт покрытия падает на строках с `assert()`.

### 2.2. Токен YouTrack

1. YouTrack → аватар → **Profile** → **Account Security** → **Tokens** → **New token…**, scope: *YouTrack*.
2. Скопируйте токен (`perm-…`). Он показывается один раз.

Токен никогда не коммитится в репозиторий и не вставляется в файлы проекта.

### 2.3. Переменные окружения

**Вариант 1 (рекомендуется): `.env` проекта** (он не коммитится). `php artisan agentio:run` передаёт значения из конфига в окружение цикла и агентов, а `scripts/yt.php` при запуске вручную сам читает `.env`, если переменных нет в окружении:

```dotenv
YOUTRACK_URL=https://<instance>.youtrack.cloud
YOUTRACK_TOKEN=perm-...
AGENTIO_PROJECT={{project}}
```

**Вариант 2: переменные окружения** — например, в `~/.zshrc` или в любом другом файле **вне репозитория** (для фоновых запусков удобнее `~/.zshenv`). Они нужны, если вы запускаете `scripts/agent-loop.sh` напрямую, без `agentio:run`:

```bash
export YOUTRACK_URL="https://<instance>.youtrack.cloud"
export YOUTRACK_TOKEN="perm-..."
```

Эти переменные используют:
- `scripts/yt.php`: без них — ошибка `YOUTRACK_URL and YOUTRACK_TOKEN are required (environment variables or the project .env file).`;
- `scripts/agent-loop.sh`: без них не стартует (`agentio:run` передаёт их сам);
- `scripts/epic-worktree.sh`: по ним вычисляет slug ветки;
- headless-агенты: через `.claude/agents-mcp.json`.

### 2.4. MCP YouTrack

**Для агентов цикла** ничего настраивать не нужно. `scripts/agent-loop.sh` запускает их с `--strict-mcp-config --mcp-config .claude/agents-mcp.json`, а в этом файле стоят подстановки из окружения:

```json
"youtrack": {
    "type": "http",
    "url": "${YOUTRACK_URL}/mcp",
    "headers": { "Authorization": "Bearer ${YOUTRACK_TOKEN}" }
}
```

**Для вашей интерактивной работы** (`claude` в каталоге проекта) подключите MCP один раз:

```bash
claude mcp add --transport http youtrack https://<instance>.youtrack.cloud/mcp \
  --header "Authorization: Bearer perm-..."
claude mcp list      # youtrack: https://<instance>.youtrack.cloud/mcp (HTTP) - ✔ Connected
```

По умолчанию сервер добавляется в scope `local`, то есть только для текущего каталога. Конфигурация хранится в `~/.claude.json`, вне репозитория. Чтобы MCP работал и в каталогах worktree (`../worktrees/<EPIC>`), добавьте его с `-s user` или запускайте там `claude --mcp-config .claude/agents-mcp.json` (см. 4.5). Laravel Boost (если установлен) подключается из `.mcp.json` проекта.

### 2.5. Проверка

```bash
php artisan agentio:status         # цикл, сессии, счётчики по типам и статусам: доступ к REST работает
php scripts/yt.php sync-stage      # "N issue(s) checked, 0 fixed": Stage совпадает со State
php artisan agentio:run --dry-run  # что запустил бы цикл; ничего не меняет
```

### 2.6. Схема проекта {{project}} в YouTrack

`php artisan agentio:install --youtrack` настраивает проект идемпотентно (находит существующее по имени и ничего не дублирует; `--dry-run` только показывает план) и записывает ID статей в `.agentio.json` и в скиллы:

- **State**: Backlog, Analysis, Ready, In Progress, Review, Blocked, Done.
- **Type**: Idea, Epic, Story, Task.
- **Stage**: Backlog, Develop, Review, Test, Staging, Done. Выводится из State, по умолчанию Backlog.
- Метки: `idea`, `agent-claimed`.
- Связи: `Depend` (depends on / is required for), `Subtask` (subtask of / parent for), `Relates`.
- Сохранённые поиски: «{{project}}: готовые эпики», «{{project}}: готовые задачи», «{{project}}: заблокированные (причина в [AGENT:BLOCKED])», «{{project}}: идеи без плана», «{{project}}: в работе у агентов», «{{project}}: эпики на приёмке».
- Kanban-доску с колонками по Stage команда не создаёт: создайте её в YouTrack (Agile-доски → создать доску, колонки — по полю Stage).
- База знаний (новые статьи — короткие заготовки, существующие с тем же заголовком не меняются): «Обзор продукта» {{kb.overview}}, «Системная аналитика» {{kb.analysis}} (слои {{kb.analysis.business}}…{{kb.analysis.constraints}}), «Архитектура» {{kb.architecture}} ({{kb.architecture.overview}}, {{kb.architecture.data_model}}), ADR {{kb.adr}} (ADR-001 {{kb.adr.001}}), «Глоссарий» {{kb.glossary}}, «Процесс разработки» {{kb.process}}, «Руководство по автоматизации» {{kb.guide}}.

---

## 3. Работа с задачами

### 3.1. Добавить идею

**Вариант 1: YouTrack UI.** Создайте задачу в проекте {{project}}:
- Summary: `[IDEA] <коротко>`.
- Описание: свободный текст — что нужно, кому и зачем. Технические детали не обязательны.
- Поля: `Type = Idea`, `State = Backlog`. Stage оставьте по умолчанию (Backlog).
- Метка: `idea`.

Цикл считает идеей задачу с меткой `idea` **или** `Type = Idea`, в `State = Backlog` и без `agent-claimed`. Проверка: `php scripts/yt.php ideas`. Ближайший проход цикла её спланирует. Можно не ждать:

```bash
claude "/plan {{project}}-1"
```

**Вариант 2: из терминала.** Агент сам создаст идею и сразу её спланирует:

```bash
claude "/plan Страница профиля пользователя с загрузкой аватара"
```

### 3.2. Что происходит при планировании

`/plan` проходит процедуру `project-manager` целиком:
1. Захватывает идею (`[AGENT:START]`, State → Analysis).
2. Дописывает в описание идеи раздел `## Требования`: цель, пользователи, границы, критерии успеха.
3. `system-analyst` обновляет статьи «Системная аналитика» и оставляет в идее `[AGENT:DECISION]` со сводкой влияния по 7 слоям.
4. Создаёт эпики (State Analysis, связь `relates to` с идеей). `laravel-architect` пишет для каждого статью «Эпик {{project}}-N: …» под «Архитектурой», при необходимости ADR, а также порядок реализации.
5. Декомпозирует эпики на STORY (критерии Given/When/Then) и TASK (по шаблону). Расставляет `depends on` по логике и по пересечению файлов; все миграции эпика выстраивает в цепочку.
6. Проверяет `php scripts/yt.php validate <IDEA>` и переводит описанное в Ready в порядке TASK → STORY → EPIC.
7. Пишет в идею `[AGENT:DONE]` с эпиками, статьями, первой волной задач и деревом. Идея → Done.

Лог сессии: `php scripts/agent-log.php plan-{{project}}-1`. Идеи планируются по одной: параллельные аналитики перезаписывали бы статьи друг друга. Повторный `/plan` по уже спланированной идее ничего не дублирует, а только доделывает недостающее.

### 3.3. Ответить на вопросы аналитика или PM

Если без человека не обойтись (бизнес-правило, роль, объём, внешняя система), агент не додумывает:
1. Он пишет в идею `[AGENT:BLOCKED]`: «Что мешает», «Что нужно от человека» (обычно варианты 1/2/3), «Как продолжить». Идея переходит в `Blocked`.
2. Вы отвечаете **комментарием в той же задаче**, например «Вариант 2, лимит 5 МБ». Если ответ меняет суть идеи, поправьте и описание.
3. Верните прежний статус. Для идеи это `Backlog`: в UI или командой `php scripts/yt.php set-state {{project}}-1 Backlog`.
4. Следующий проход цикла (или `claude "/plan {{project}}-1"`) продолжит планирование. Агент читает все комментарии, включая ваш ответ.

С заблокированными EPIC, STORY и TASK — то же самое, только статус возвращается в `Ready` (см. раздел 7).

### 3.4. Добавить историю или задачу в существующий эпик

**Попросить агента** (рекомендуется). PM сам оформит задачи по шаблонам, расставит зависимости и проверит граф:

```bash
claude "Примени project-manager: в эпик {{project}}-2 добавить историю — пользователь может удалить аватар. Критерии: ..."
```

**Вручную в YouTrack:**
1. STORY: summary `[STORY] …`, `Type = Story`, родитель (**subtask of**) — EPIC, описание по шаблону (сценарий и критерии приёмки Given/When/Then).
2. TASK: summary `[TASK] …`, `Type = Task`, родитель — STORY (не эпик!). Описание по шаблону: Контекст, Что сделать, Критерии приёмки, Затрагиваемые области кода, Вне рамок. Шаблоны лежат в `.claude/skills/youtrack-workflow/issue-templates.md`.
3. Если задача трогает те же файлы, что и другая незакрытая задача, добавьте зависимость (3.6).
4. Когда описание полное, переведите TASK и STORY в `Ready`.
5. Проверьте граф: `php scripts/yt.php validate {{project}}-2` и `php scripts/yt.php tree {{project}}-2` (у готовой задачи метка `[READY]`).

Если эпик в работе (`In Progress`), оркестратор подхватит новую готовую задачу в одной из следующих волн. Если эпик в `Review` или `Done`, верните его в `Ready` (`php scripts/yt.php set-state {{project}}-2 Ready`): цикл продолжит работу в том же worktree и на той же ветке.

### 3.5. Изменить требования

Порядок такой: сначала статья, потом задачи. Уже сделанные TASK не переписываются — под изменение создаются новые.

```bash
claude "Примени project-manager и system-analyst: изменение требований к эпику {{project}}-2 — <что меняется и почему>"
```

Если правите вручную: исправьте описание STORY или TASK, которые ещё не взяты (`Ready`/`Backlog`), и соответствующую статью базы знаний. В задаче оставьте комментарий, что изменилось. Задачу в `In Progress` не правьте молча: агент уже работает по старому описанию. Дождитесь её завершения и добавьте новую TASK.

### 3.6. Добавить зависимость

«A зависит от B» — A не начнётся, пока B не в `Done` (или не в `Review` внутри того же эпика):
- **YouTrack UI**: в задаче A → Link issue → **depends on** → B.
- **Агент**: `link_issues(targetIssueId=A, linkType="depends on", issueToLinkId=B)`.

Затем проверьте отсутствие циклов: `php scripts/yt.php validate <EPIC>`. Зависимость, поставленная на STORY или EPIC, наследуется всеми их задачами. Межэпиковые зависимости ставятся на уровне EPIC → EPIC.

### 3.7. Задача вне эпика

Цикл берёт в работу **только эпики**. TASK без цепочки TASK → STORY → EPIC не попадёт ни в `ready-epics`, ни в `ready-tasks`, а `validate` пометит её как ошибку структуры. Варианты:
- положить задачу в подходящую STORY существующего эпика (3.4);
- завести небольшой эпик: `[EPIC] Техдолг …` → `[STORY] …` → `[TASK] …`, все поля `Type`, родители, State `Ready`, затем `validate`;
- описать её как идею (`[IDEA]`) и отдать планированию.

Правила для любого способа:
- префикс summary совпадает с `Type`;
- у TASK родитель — STORY, у STORY — EPIC;
- в `Ready` переводится только полностью описанное;
- после правок нужен `validate`.

### 3.8. Сменить статус вручную

Можно в интерфейсе YouTrack: Stage подтянется при следующем `sync-stage`, то есть в начале прохода цикла. Сразу с Stage — командой:

```bash
php scripts/yt.php set-state {{project}}-42 Ready
php scripts/yt.php set-state {{project}}-42 Ready --comment="Ответ получен, возвращаю в работу"
php scripts/yt.php sync-stage {{project}}-42        # подтянуть Stage одной задачи
```

Не снимайте метку `agent-claimed` руками у задачи, с которой работает живая сессия. Чтобы снять захват, используйте `release` (раздел 7).

---

## 4. Запуск и остановка

### 4.1. Посмотреть, что будет запущено (ничего не меняет)

```bash
php artisan agentio:run --dry-run  # идеи, возобновляемые и готовые эпики, первая волна задач, дерево, блокировки, расхождения Stage
claude "/dispatch"                 # то же с пояснениями: что запустится и почему задача не берётся
```

### 4.2. Один проход

```bash
php artisan agentio:run --once            # спланировать идеи, запустить готовые эпики и дождаться конца их сессий
php artisan agentio:run --once --no-wait  # запустить и сразу выйти; сессии работают в фоне
```

`php artisan agentio:run` принимает те же опции, что и `scripts/agent-loop.sh`, и передаёт циклу окружение из `config/agentio.php` (`YOUTRACK_*`, `AGENTIO_PROJECT`, `BASE_BRANCH`, `MAX_PARALLEL` и т. д.). Скрипт можно запускать и напрямую — тогда переменные должны быть экспортированы.

### 4.3. Постоянный режим в фоне

```bash
nohup php artisan agentio:run > /dev/null 2>&1 &
tail -f storage/logs/agents/loop.log
```

Если запускаете `scripts/agent-loop.sh` напрямую, переменные `YOUTRACK_*` должны быть экспортированы в том shell, из которого запускается `nohup`. Второй цикл в том же каталоге не стартует: работающий цикл держит `storage/logs/agents/loop.pid`. Проверка — `php artisan agentio:status` или `pgrep -fa agent-loop.sh`.

### 4.4. Ограничить работу

```bash
php artisan agentio:run --once --epic={{project}}-2    # только этот эпик; идеи не планируются
php artisan agentio:run --no-plan             # не планировать идеи, только работать над эпиками
php artisan agentio:run --once --max-parallel=0   # только спланировать идеи, эпики не запускать
php artisan agentio:run --interval=600        # проход раз в 10 минут
```

### 4.5. Ручной запуск оркестратора эпика (интерактивно)

Так видно всё в реальном времени, и можно вмешаться:

```bash
scripts/epic-worktree.sh {{project}}-2          # создаст (или переиспользует) и подготовит ../worktrees/{{project}}-2, печатает путь
cd ../worktrees/{{project}}-2
claude --mcp-config .claude/agents-mcp.json "/work-epic {{project}}-2"
```

`/work-epic` проверяет, что текущая ветка — `epic/{{project}}-2-*`; в главном каталоге он откажется работать. `--mcp-config` нужен, если MCP `youtrack` добавлен в scope `local` главного каталога (см. 2.4).

### 4.6. Параметры

Опции командной строки (у `agentio:run` и `scripts/agent-loop.sh`): `--once`, `--dry-run`, `--kill`, `--interval=SEC`, `--max-parallel=N`, `--max-parallel-tasks=N`, `--epic={{project}}-N`, `--no-plan`, `--no-wait`; у скрипта ещё `-h`/`--help`. Только у `agentio:run`: `--stop` (поставить флаг остановки и выйти) и `--fresh` (снять оставшийся флаг остановки перед запуском).

Переменные окружения (в скобках — ключ `config/agentio.php`, из которого их берёт `agentio:run`):

| Переменная | По умолчанию | Смысл |
|---|---|---|
| `YOUTRACK_URL`, `YOUTRACK_TOKEN` (`youtrack.url`, `youtrack.token`) | — (обязательны) | Доступ к YouTrack |
| `AGENTIO_PROJECT` (`youtrack.project`) | `{{project}}` | Ключ проекта YouTrack |
| `MAX_PARALLEL` (`max_parallel`) | 2 | Сколько эпиков (worktree) работает одновременно |
| `MAX_PARALLEL_TASKS` (`max_parallel_tasks`) | 2 | Сколько субагентов `task-developer` работает параллельно внутри эпика |
| `AGENT_LOOP_INTERVAL` (`interval`) | 300 | Пауза между проходами, сек |
| `BASE_BRANCH` (`base_branch`) | `{{base_branch}}` | От какой ветки создаются `epic/*` |
| `WORKTREES_DIR` (`worktrees_path`) | `<репозиторий>/../worktrees` | Где создаются worktree |
| `CLAUDE_BIN` (`claude_binary`) | `claude` | Исполняемый файл Claude Code |
| `CLAUDE_MODEL` | — | Модель агентов (`opus`, `sonnet`, …) |
| `MAX_RESTARTS` | 3 | Сколько раз возобновлять сессию эпика, оборвавшуюся до Review, прежде чем пометить эпик Blocked |
| `MERGE_POLICY` (`merge_policy`) | строка `MERGE_POLICY:` в `CLAUDE.md` | Политика слияния (6.7) |
| `AGENT_LOG_DIR` (`logs_path`) | `storage/logs/agents` | Логи и pid-файлы цикла |
| `AGENTIO_TEST_COMMAND`, `AGENTIO_FULL_TEST_COMMAND` (`tests.command`, `tests.full_command`) | `php artisan test --compact`; `composer test` (если есть такой скрипт) | Команды `scripts/run-tests.sh`: узкий прогон и полный гейт |
| `WORKTREE_SQLITE`, `WORKTREE_PORT_BASE` | 1; 8100 | `scripts/epic-worktree.sh`: своя SQLite-БД в worktree; порт `APP_URL` = база + номер эпика |

Политика слияния без `MERGE_POLICY` в окружении читается из строки `MERGE_POLICY:` в `CLAUDE.md` (6.7).

### 4.7. Что делает один проход

1. **`sync-stage`**: приводит Stage в соответствие со State. В лог попадают только исправления и ошибки, и ошибка не останавливает цикл.
2. **Подбор завершившихся сессий.** Эпик в `Review` — готов к приёмке (с политикой `auto-merge` без remote цикл сам сливает его в `{{base_branch}}`). Эпик ещё `In Progress` — сессия оборвалась, следующий проход её возобновит (счётчик `<EPIC>.restarts`).
3. **Запуск эпиков** в пределах `MAX_PARALLEL`: сначала свои незавершённые (захвачены этой машиной, worktree есть, процесс не жив), потом готовые. Для каждого:
   - `scripts/epic-worktree.sh <ID>` создаёт worktree `../worktrees/<ID>` на ветке `epic/<ID>-<slug>` с собственным окружением: `.env` из `.env.example`, своя SQLite-БД (`WORKTREE_SQLITE=0` — оставить настройки БД из `.env.example`), свои префиксы кэша, очередей и cookie, `APP_URL=http://localhost:<8100 + номер эпика>`, `composer install`, `key:generate`, миграции, `storage:link`; если есть lock-файл bun или npm — установка JS-пакетов и, при наличии скрипта `build`, сборка фронтенда до 3 попыток; в конце — `scripts/epic-worktree.local.sh <ID>`, если такой исполняемый файл есть в проекте (место для своих шагов: сидеры, сервисы и т. п.);
   - затем в фоне запускается `claude -p "/work-epic <ID>"` с логом `storage/logs/agents/<ID>.log`. Уже работающий эпик повторно не запускается.
4. **Планирование идей** по одной (`/plan`, лог `plan-<IDEA>.log`), затем ещё раз запуск эпиков: только что спланированные могут быть уже готовы.

Перед запуском эпика цикл проверяет, что инфраструктура агентов закоммичена в `BASE_BRANCH`. Сессия эпика работает со скиллами, агентами и скриптами **из своего worktree**, то есть из ветки эпика. Изменения процесса в `{{base_branch}}` попадут в уже созданный эпик только после слияния `{{base_branch}}` в его ветку.

### 4.8. Остановка

```bash
php artisan agentio:run --stop     # то же, что touch .agent-stop: цикл доработает текущий шаг и выйдет; сессии эпиков доработают сами
php artisan agentio:run --fresh    # снять флаг и запустить (иначе цикл сразу выйдет); вручную — rm .agent-stop
php artisan agentio:run --kill     # прервать работающие сессии эпиков (SIGTERM группе процессов)
```

- **Ctrl+C** в терминале цикла (и SIGTERM процессу `agentio:run` — он передаёт сигнал циклу) работает как `.agent-stop`: цикл завершает текущий шаг и выходит, запущенные сессии продолжают работать (они запущены через `setsid`).
- Текущее планирование идеи цикл дожидается до конца.
- После `--kill` захват эпика сохраняется, и следующий запуск его возобновит. Но каждое такое завершение до Review засчитывается как попытка в счётчик `MAX_RESTARTS`.

### 4.9. Возобновление после обрыва

Ничего специально делать не нужно: просто запустите цикл снова (`--once` или фоновый режим).
- Эпик `In Progress` с захватом этой машины (owner `<hostname>:<WORKTREES_DIR>/<ID>`) и живым worktree будет возобновлён.
- `claim` вернёт `RESUMED`. Оркестратор восстановит состояние по YouTrack и git: осиротевшие TASK `In Progress`, незакоммиченные изменения, истории, ждущие ревью. В эпик он запишет `[AGENT:DECISION] Возобновление: …`.
- Разработчик задачи продолжит со своего `[AGENT:START]` / `[AGENT:DONE]`, сверяясь с `git log --grep=<TASK>`.

Если эпик захвачен другой машиной или старым путём, цикл его не тронет. Что делать — см. раздел 7.

---

## 5. Наблюдение

| Что | Команда / место |
|---|---|
| Сводка: что в работе, что заблокировано, что ждёт вас | `php artisan agentio:status` (`--json`), `claude "/status"` или `php scripts/yt.php status` |
| Всё сразу в браузере: цикл, сессии и их последние действия, конвейер идей и эпиков, блокировки, лента `[AGENT:*]` | страница `/agentio` (в `local` — всем, иначе — по gate `viewAgentio`) |
| Что запустится и почему задача не берётся | `claude "/dispatch"`, `php artisan agentio:run --dry-run` |
| Дерево эпика с готовностью и ожиданиями | `php scripts/yt.php tree {{project}}-2` (`[READY]`, `[CLAIMED]`, `waits:{{project}}-…`) |
| Заблокированные и ждущие зависимостей | `php scripts/yt.php blocked` |
| Захваченные эпики и их владельцы | `php scripts/yt.php claimed-epics` |
| Идеи к планированию / готовые эпики / готовые задачи | `php scripts/yt.php ideas`, `ready-epics`, `ready-tasks {{project}}-2` |
| Полный контекст задачи | `php scripts/yt.php context {{project}}-5` (описание, родители, дети, зависимости, владелец, все `[AGENT:*]`) |
| Лента действий агента | `php scripts/agent-log.php {{project}}-2 --lines=60`; в реальном времени — `--follow` |
| Лог планирования | `php scripts/agent-log.php plan-{{project}}-1` |
| Журнал цикла | `tail -f storage/logs/agents/loop.log` |
| Подготовка worktree | `storage/logs/agents/{{project}}-2.setup.log` |
| Коммиты эпика | `git log --oneline {{base_branch}}..epic/{{project}}-2-<slug>` |
| Доска | YouTrack → Agile-доски → Kanban-доска проекта (колонки по Stage) |
| Сохранённые поиски | «{{project}}: в работе у агентов», «{{project}}: заблокированные …», «{{project}}: эпики на приёмке» и др. |

**Как читать `agent-log.php`.** `== session …` — старт сессии с моделью и каталогом. `💬` — текст агента. `🔧 Инструмент: аргумент` — вызов инструмента. `↳` — действие субагента. `⚠️` — ошибка инструмента (например, отклонённая команда). `== result: … cost=$… duration=…min` — итог сессии.

**Как читать `loop.log`.** `agent loop started: mode=… policy=… max_parallel=…`, `{{project}}-2: preparing worktree`, `{{project}}-2: started /work-epic (pid …)`, `{{project}}-2: session finished, epic state: Review` → `ready for human review on branch …`, `ended before Review (attempt 1/3), will be resumed`, `sync-stage: {{project}}-5 State=Done: Stage Develop -> Done`.

**Комментарии `[AGENT:*]` в задачах** — журнал работы, читается сверху вниз:
- `[AGENT:START]` — кто захватил (`owner: host:/путь[#суффикс]`), ветка, worktree, план и затрагиваемые файлы;
- `[AGENT:DECISION]` — принятое решение и отвергнутые варианты. Сюда же относятся сводка влияния аналитика, порядок реализации от архитектора, прогресс оркестратора и вердикт ревью `CHANGES REQUESTED (раунд N)`;
- `[AGENT:BLOCKED]` — что мешает и что нужно **от вас**;
- `[AGENT:DONE]` — что сделано, хеши коммитов, как проверить, результаты тестов, что осталось. В STORY — вердикт `APPROVED`;
- `[AGENT:RELEASE]` — захват снят без завершения.

Активный владелец задачи — самый ранний `[AGENT:START]` после последнего `DONE`/`BLOCKED`/`RELEASE`.

---

## 6. Ревью и приёмка эпика

### 6.1. Где результат

Эпик в `Review` (колонка Review на доске, поиск «{{project}}: эпики на приёмке», раздел «Awaiting human» в `yt.php status`). В `loop.log` будет строка `ready for human review on branch epic/{{project}}-2-<slug> (worktree kept: …/worktrees/{{project}}-2)`.
- Ветка: `git branch --list 'epic/{{project}}-2-*'`.
- Worktree: `../worktrees/{{project}}-2`.

### 6.2. Что смотреть в `[AGENT:DONE]` эпика

- STORY и их вердикты (все `APPROVED`).
- Список коммитов (`git log --oneline {{base_branch}}..HEAD`): каждый коммит начинается с ID задачи.
- Результат полного прогона (`RUN_TESTS_FULL=1 scripts/run-tests.sh`).
- Ветка или PR и команды приёмки.
- Созданная смежная работа: новые TASK в Backlog, которые требуют вашего решения.

Также проверьте `[AGENT:DONE]` по STORY: там описано, каким тестом подтверждён каждый критерий приёмки.

### 6.3. Посмотреть изменения и запустить приложение

```bash
git log --oneline {{base_branch}}..epic/{{project}}-2-<slug>
git diff {{base_branch}}...epic/{{project}}-2-<slug> --stat
cd ../worktrees/{{project}}-2
grep APP_URL .env                    # http://localhost:<8100 + номер эпика>, для {{project}}-2 — 8102
php artisan serve --port=8102        # откройте APP_URL; очереди — php artisan queue:work (или как принято в проекте)
```

У worktree собственные `.env`, SQLite-база (`database/database.sqlite`) и префиксы кэша и очередей, так что он не мешает главному каталогу. Фронтенд в нём уже собран (если у проекта есть сборка).

### 6.4. Прогнать тесты

```bash
cd ../worktrees/{{project}}-2
RUN_TESTS_FULL=1 scripts/run-tests.sh      # полный гейт проекта (AGENTIO_FULL_TEST_COMMAND, по умолчанию composer test)
scripts/run-tests.sh --filter=Profile      # выборочно (AGENTIO_TEST_COMMAND, по умолчанию php artisan test)
```

Вывод пишется в `storage/logs/tests/<время>-<pid>.log`, на экран — краткий итог и `exit=… log=…`.

### 6.5. Принять

```bash
cd <главный каталог проекта>                        # ветка {{base_branch}}, рабочее дерево чистое
git merge --no-ff epic/{{project}}-2-<slug>
php scripts/yt.php tree {{project}}-2                        # ID историй эпика
php scripts/yt.php set-state {{project}}-3 Done              # каждую STORY
php scripts/yt.php set-state {{project}}-2 Done              # эпик
scripts/epic-worktree.sh {{project}}-2 --remove              # удалить worktree (откажется, если в нём есть незакоммиченное); ветка остаётся
git branch -d epic/{{project}}-2-<slug>                      # по желанию
```

Закрытие можно поручить агенту: `claude "Примени project-manager: закрой эпик {{project}}-2 после слияния"`. Он проверит слияние (`git branch --merged {{base_branch}}`) и закроет STORY и EPIC.

### 6.6. Вернуть на доработку

1. Оставьте комментарий в эпике или нужной STORY: что не так.
2. Создайте TASK с замечанием в нужной STORY (`[TASK] …`, `Type = Task`, родитель — STORY, описание по шаблону, `State = Ready`). Можно попросить агента: `claude "Примени project-manager: замечания по эпику {{project}}-2 — ..."`.
3. По желанию переведите STORY в `Ready`, чтобы на доске она ушла из колонки Review: `php scripts/yt.php set-state {{project}}-3 Ready`. После новых задач оркестратор снова запустит ревью этой истории.
4. Верните эпик в `Ready`: `php scripts/yt.php set-state {{project}}-2 Ready`.

Цикл продолжит работу **в том же worktree и на той же ветке**. Не удаляйте worktree, пока эпик не принят.

### 6.7. Политики слияния

Политика задаётся строкой `MERGE_POLICY:` в `CLAUDE.md` (её ставит `agentio:install --merge-policy=…`) или переменной `AGENTIO_MERGE_POLICY` в `.env`. При установке выбрано `{{merge_policy}}`.

| Значение | Что делают агенты | Что делает цикл | Кто сливает |
|---|---|---|---|
| `local-branch` | Ничего не пушат, ветка остаётся в worktree | Пишет в лог, что эпик готов к приёмке | Человек, локально |
| `pull-request` | `git push -u origin <ветка>`, `gh pr create` | Если PR есть, удаляет worktree | Человек, в PR |
| `auto-merge` | Как `pull-request`, плюс `gh pr merge --auto --merge` | Без remote — локальный `git merge --no-ff` в `{{base_branch}}`, только если главный каталог чистый и стоит на `{{base_branch}}`; при конфликте оставляет человеку | Автоматически |

Для PR нужны `origin`, установленный `gh` и выполненный `gh auth login`. Push в `{{base_branch}}` агентам запрещён при любой политике.

---

## 7. Blocked, сбои и частые проблемы

### 7.1. Разбор Blocked

1. Найдите заблокированные: `php scripts/yt.php blocked` (с причинами), поиск «{{project}}: заблокированные …» или `claude "/status"`.
2. Прочитайте последний `[AGENT:BLOCKED]`: что мешает, что нужно от вас, что уже сделано, как продолжить.
3. Ответьте **комментарием в задаче**. Если ответ меняет требования, поправьте описание или статью.
4. Верните статус, который был до блокировки: обычно `Ready` для TASK/STORY/EPIC и `Backlog` для идеи (`php scripts/yt.php set-state <ID> <State>`).
5. Если заблокирован **эпик** (его сессия встала), сначала разблокируйте вложенные задачи, потом верните эпик в `Ready`. Цикл продолжит в том же worktree.

Можно поручить разбор PM: `claude "Примени project-manager: разбери блокировки"`. Если вы ответили комментарием, он внесёт ответ в описание или статью и вернёт статус. Если ответа нет, он ничего не делает.

### 7.2. Частые проблемы

| Симптом | Причина и решение |
|---|---|
| `YOUTRACK_URL and YOUTRACK_TOKEN are required …` или `YOUTRACK_URL and YOUTRACK_TOKEN must be set …` | Нет значений ни в `.env`, ни в окружении (2.3). Запускайте цикл через `php artisan agentio:run`; при прямом запуске скрипта экспортируйте переменные в том же shell (для `nohup` — или в `~/.zshenv`). |
| В логе агента нет инструментов `mcp__youtrack__*` | Неверный токен или URL. Проверьте: `php scripts/yt.php status`. Агенты берут MCP из `.claude/agents-mcp.json`. |
| Тесты «висят» после прохождения | Сиротский процесс `playwright run-server` от браузерных тестов держит stdout, и `<тесты> \| tail` ждёт вечно. Запускайте тесты **только** через `scripts/run-tests.sh`: он пишет вывод в файл и убивает такие процессы своего каталога. Вручную: `pkill -f '^node .*playwright run-server'`. |
| Строгий гейт покрытия падает на строках с `assert()` | Системный php.ini с `zend.assertions=-1`. Используйте `RUN_TESTS_FULL=1 scripts/run-tests.sh` (включает assertions сам) или выставьте `zend.assertions = 1`. |
| В логе агента `⚠️` с отказом в разрешении на команду с `$(...)`, обратными кавычками или `$VAR` | Headless-агенты работают в `--permission-mode dontAsk`: команды с подстановками не совпадают с белым списком и отклоняются без вопроса. Поэтому `claim` сам берёт ветку и worktree из git, а скиллы требуют писать значения буквально. Если агенту нужна новая безопасная команда, добавьте правило в `.claude/settings.json` → `permissions.allow` и закоммитьте в `{{base_branch}}` (`agentio:install` при обновлении сохраняет ваши правила: списки объединяются). |
| `claim` вернул `LOST: {{project}}-… is claimed by …` (код 3) | Задачу держит другой владелец, и агент её не трогает. Это нормально при гонке двух агентов или машин. Если владелец «мёртв» (старый путь, другая машина), снимите захват: `php scripts/yt.php release {{project}}-2 --state=Ready --comment="[AGENT:RELEASE] перезапуск на другой машине"`. |
| Эпик в `In Progress`, но никто не работает | Сессия оборвалась. Следующий проход возобновит её, если worktree на этой машине и owner совпадает (`php scripts/yt.php claimed-epics`). Иначе — `release`, как в строке выше. |
| Две сессии одного эпика / повторный запуск | Цикл не запускает эпик, у которого жив процесс из `<EPIC>.pid`. Не запускайте два цикла одновременно и не стартуйте `/work-epic` вручную для эпика, с которым работает цикл. Проверка: `pgrep -fa agent-loop.sh`, `php scripts/yt.php claimed-epics`. Повторный `/plan` по идее и повторный `/work-epic` безопасны: они продолжают с места остановки. |
| Эпик сам ушёл в `Blocked` с текстом «сессия /work-epic N раза подряд завершилась…» | Сессия обрывалась до Review больше `MAX_RESTARTS` раз подряд (сюда же считаются `--kill`). Смотрите `php scripts/agent-log.php {{project}}-2 --lines=100`, устраните причину и верните эпик в `Ready`. |
| `worktree setup failed, see …/{{project}}-2.setup.log` | Частые причины: нет сети для `composer`/`npm`/`bun`, не прошли миграции, не собрался фронтенд (делается 3 попытки). Повтор: `scripts/epic-worktree.sh {{project}}-2`. |
| Агент «не видит» задачу как готовую | `php scripts/yt.php tree <EPIC>` покажет `waits:` (незакрытые зависимости, в том числе унаследованные) или `[CLAIMED]`. TASK вне иерархии TASK → STORY → EPIC цикл не берёт (3.7). |
| Карточка на доске не в той колонке | Stage разошёлся со State, например после правки State в UI. `php scripts/yt.php sync-stage` (или подождите начала прохода цикла). |
| Поиск `State: Backlog` показывает лишнее | Он находит и задачи со `Stage = Backlog`. Используйте `php scripts/yt.php ideas` / `status`. |
| Хук отклонил команду агента (`guard-bash: …`) | Так задумано (раздел 8). Агент должен выбрать безопасный путь или написать `[AGENT:BLOCKED]`. |
| Изменения в скиллах или скриптах не действуют в эпике | Сессия эпика использует `.claude/` и `scripts/` из своего worktree. Закоммитьте изменения в `{{base_branch}}` и влейте `{{base_branch}}` в ветку эпика, либо дождитесь следующего эпика. |
| `ERROR: agent infrastructure is not committed to {{base_branch}}` | `.claude/agents-mcp.json` и прочее не закоммичены в `BASE_BRANCH`, и worktree их не получит. Закоммитьте. |
| `Another agent loop is already running` | Цикл в этом каталоге уже работает (`storage/logs/agents/loop.pid`). Остановите его (`php artisan agentio:run --stop`) или дождитесь выхода. Если процесса нет, а файл остался — удалите `loop.pid`. |
| Две машины работают с одним YouTrack | Захват защищает от гонки: проигравший получает `LOST`. Worktree, pid-файлы и логи видны только локально. |

---

## 8. Безопасность

- **Режим разрешений.** Headless-агенты запускаются с `--permission-mode dontAsk`. Им разрешено только то, что перечислено в `.claude/settings.json` → `permissions.allow`; остальное отклоняется без вопроса. Там же список `deny`:
  - `git push --force`, push в `{{base_branch}}`/`main`/`master`/`HEAD`;
  - `reset --hard`, `clean`, `rebase`, `checkout {{base_branch}}`, `worktree remove`;
  - `composer require/remove/update`, `bun add/remove/update`, `npm install <пакет>/uninstall/update`, `sudo`;
  - чтение `.env.production`/`.env.staging`, `~/.claude.json`, `~/.ssh`, `~/.config/gh`.
- **Самозащита.** `.claude/agent-settings.json` запрещает агентам править свои настройки (`.claude/settings*.json`, `agent-settings.json`, `agents-mcp.json`), хуки и скрипты цикла (`agent-loop.sh`, `epic-worktree.sh`, `agent-commit.sh`, `run-tests.sh`).
- **Хук `.claude/hooks/guard-bash.php`** (PreToolUse для Bash) — второй рубеж. Он запрещает:
  - push в защищённые ветки, force/mirror push, push и удаление веток кроме `epic/*`;
  - `reset --hard`, `clean`, `stash`, переписывание истории, `checkout`/`restore` всего дерева, `worktree remove/prune`;
  - `rm`/`mv`/`chmod`/`find -delete` вне каталога проекта (и разрешённых `additionalDirectories`) и внутри `.git`;
  - `git -C` вне проекта;
  - чтение секретов;
  - вывод `$YOUTRACK_TOKEN`, `ANTHROPIC_*`, `printenv`, `env`;
  - `sudo` и разрушительные системные команды.
- **Зависимости.** Новые пакеты агентам запрещены: они спрашивают через `[AGENT:BLOCKED]`.
- **Коммиты.** Агенты коммитят только свои файлы через `scripts/agent-commit.sh`, а `git add -A`/`.` и `commit -a` запрещены правилами процесса.
- **Изоляция окружения.** Каждый worktree получает свой `.env` из `.env.example` (локальное окружение, свой `APP_KEY`, своя SQLite-БД). Главный `.env` агенты не используют.
- **Токены.** `YOUTRACK_TOKEN` живёт только в окружении или в `.env` (он не коммитится; в `.env.example` переменная пустая). `scripts/yt.php` и `.claude/agents-mcp.json` (через `${YOUTRACK_TOKEN}`) читают его сами, в репозиторий, логи и комментарии он не попадает. Ваш интерактивный MCP хранит токен в `~/.claude.json`. Скомпрометированный токен отзовите в Profile → Account Security → Tokens.

---

## 9. Шпаргалка

```bash
# Установка и настройка
composer require obrazmisli/agentio --dev
php artisan agentio:install --project={{project}}      # файлы агентов, CLAUDE.md, .gitignore, .env.example
# .env: YOUTRACK_URL=https://<instance>.youtrack.cloud  YOUTRACK_TOKEN=perm-...  AGENTIO_PROJECT={{project}}
php artisan agentio:install --youtrack --dry-run       # план настройки YouTrack; без --dry-run — применить
claude mcp add --transport http youtrack https://<instance>.youtrack.cloud/mcp --header "Authorization: Bearer perm-..."
php artisan agentio:status

# Идеи и планирование
claude "/plan <текст идеи>"            # создать идею и спланировать
claude "/plan {{project}}-1"                    # спланировать существующую идею
php scripts/yt.php ideas               # идеи, ждущие планирования
php scripts/yt.php validate {{project}}-2       # проверить структуру эпика или идеи

# Цикл
php artisan agentio:run --dry-run      # что будет запущено
php artisan agentio:run --once [--epic={{project}}-2] [--no-plan] [--no-wait] [--max-parallel=N]
nohup php artisan agentio:run > /dev/null 2>&1 &
php artisan agentio:run --stop         # остановить цикл (touch .agent-stop); --fresh — снять флаг и запустить
php artisan agentio:run --kill         # прервать сессии эпиков (захват сохраняется)

# Ручной оркестратор
scripts/epic-worktree.sh {{project}}-2 && cd ../worktrees/{{project}}-2 && claude --mcp-config .claude/agents-mcp.json "/work-epic {{project}}-2"

# Наблюдение
php artisan agentio:status [--json]   |   /agentio в браузере
claude "/status"   |   claude "/dispatch"
php scripts/yt.php status | blocked | claimed-epics | ready-epics | tree {{project}}-2 | ready-tasks {{project}}-2 | context {{project}}-5
php scripts/agent-log.php {{project}}-2 --follow
tail -f storage/logs/agents/loop.log

# Статусы (State + Stage)
php scripts/yt.php set-state {{project}}-42 Ready [--comment="..."]
php scripts/yt.php sync-stage [{{project}}-42 ...] [--dry-run] [--json]
php scripts/yt.php release {{project}}-42 --state=Ready --comment="[AGENT:RELEASE] ..."   # снять захват

# Приёмка
cd ../worktrees/{{project}}-2 && RUN_TESTS_FULL=1 scripts/run-tests.sh && php artisan serve --port=8102
git merge --no-ff epic/{{project}}-2-<slug>
php scripts/yt.php set-state <STORY> Done && php scripts/yt.php set-state {{project}}-2 Done
scripts/epic-worktree.sh {{project}}-2 --remove

# Доработка
php scripts/yt.php set-state {{project}}-2 Ready  # после новых TASK в Ready
```
