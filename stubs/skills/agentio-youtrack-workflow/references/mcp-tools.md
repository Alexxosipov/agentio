# Инструменты MCP-сервера youtrack

Все вызовы — инструменты `mcp__youtrack__<имя>`. Ответ — JSON. Ошибка приходит текстом `Error: …` (например, `Issue not found`).

## Содержание

- Задачи: чтение, поиск, создание, изменение
- Комментарии: чтение до конца, запись
- Метки и связи
- Статьи базы знаний
- Чего в MCP нет

## Задачи

| Действие | Вызов | Заметки |
|---|---|---|
| Прочитать задачу | `get_issue(issueId="{{project}}-12")` | `customFields` (Type, Stage, …), `tags` (имена), `parentIssue` (`{id, summary}` или `null`), `linkedIssueCounts` (только счётчики связей), последние 5 комментариев (`recentCommentsCount` меняет число). |
| Найти задачи | `search_issues(query=…, customFieldsToReturn=["Type", "Stage"], limit=20, offset=0)` | Не больше 20 на страницу. Пока `hasNextPage` = true — повторяй с `offset` + 20. Меток в ответе нет: фильтруй ими в запросе (`tag: -{agent-claimed}`). |
| Дети / родитель / зависимости | `search_issues("subtask of: {{project}}-12")`, `"parent for: …"`, `"is required for: …"` (от чего зависит), `"depends on: …"` (что от неё зависит) | `get_issue` не перечисляет связанные задачи — только счётчики. Для дерева эпика удобнее `php artisan agentio:yt tree <EPIC>`. |
| Создать задачу | `create_issue(project="{{project}}", summary="[TASK] …", description=…, parentIssue="{{project}}-7", customFields={"Type": "Task", "Stage": "Ready"})` | Возвращает ID и URL. Допустимые поля и значения — `get_issue_fields_schema(projectKey="{{project}}")`. |
| Сменить Stage | `update_issue(issueId=…, customFields={"Stage": "Review"})` | Сначала комментарий `[AGENT:*]`. Захват и освобождение — только `php artisan agentio:yt claim/release`. |
| Изменить описание | `update_issue(issueId=…, description=<полный новый текст>)` | Описание заменяется целиком: прочитай текущее (`get_issue`) и дополни его, исходный текст человека не затирай. |

## Комментарии

- Чтение: `get_issue_comments(issueId=…, limit=10, offset=0)` — не больше 10 за раз, от старых к новым. Повторяй с `offset` + 10, пока страница не короче 10. Контекст задачи — это **все** её `[AGENT:*]`, не только последние.
- Запись: `add_issue_comment(issueId=…, text=<Markdown>)`. Первая строка — маркер `[AGENT:…]`, шаблоны — [comment-templates.md](comment-templates.md).

## Метки и связи

- `manage_issue_tags(issueId=…, tag="idea", operation="add")` (или `"remove"`). Метку `agent-claimed` ставит и снимает только `agentio:yt claim/release`.
- `link_issues(targetIssueId="{{project}}-15", linkType="depends on", issueToLinkId="{{project}}-14")` — «15 зависит от 14». `relates to` — идея ↔ эпик, смежные задачи. Иерархия — через `parentIssue` при создании (или `linkType="subtask of"`).

## Статьи базы знаний

| Действие | Вызов | Заметки |
|---|---|---|
| Прочитать | `get_article(articleId="{{project}}-A-3")` | `content`, `childArticles` (только прямые дети), `parentArticle`. Длинную статью читай до конца: пока `contentMeta.hasMoreLines`, повторяй с `linesOffset`. |
| Найти | `search_articles(query="project: {{project}} <термин>", limit=20)` | У результата есть `parentArticle`. Для обзора дерева — `php artisan agentio:yt kb-tree`. |
| Создать | `create_article(project="{{project}}", summary=…, content=…, parentArticle="{{project}}-A-2")` | Сразу под родителем. |
| Изменить | `update_article(articleId=…, content=<полный текст>)` | **Перезаписывает всю статью** (кроме `append=true`). Порядок: `get_article` полностью → правка в полном тексте → `update_article`. |
| Перенести | `update_article(articleId=…, parentArticleId="{{project}}-A-…")` | |

## Чего в MCP нет

Настройка проекта (поля, наборы значений, метки, сохранённые поиски) — дело человека: `php artisan agentio:setup-youtrack`. Если для работы не хватает поля, значения или метки — `[AGENT:BLOCKED]`, а не обходной путь через REST API.
