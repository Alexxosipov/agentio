# Шаблоны комментариев агентов

Первая строка — маркер, без пробелов перед ним. Остальное — Markdown. Пиши конкретно: пути файлов, хеши коммитов, команды. Не пиши «см. выше» — комментарий должен читаться отдельно.

## [AGENT:START]

Первый `[AGENT:START]` создаёт `php scripts/yt.php claim` (строки owner/branch/worktree). Если план длинный, допиши вторым комментарием:

```markdown
[AGENT:START]
owner: `host:/abs/path/to/worktree#{{project}}-42`
branch: `epic/{{project}}-10-profile-page`
worktree: `/abs/path/to/worktree`

**Роль:** task-developer | orchestrator | project-manager | story-reviewer
**План:**
1. ...
2. ...

**Затрагиваемые файлы:**
- `app/Actions/UpdateUserAvatar.php` (новый)
- `routes/web.php`

**Допущения:**
- ... (если допущение существенное и не следует из задачи или статей — это не допущение, а `[AGENT:BLOCKED]`)
```

## [AGENT:DECISION]

```markdown
[AGENT:DECISION]
**Решение:** храним аватар на диске `public`, путь в `users.avatar_path`.
**Почему:** ADR-001 ({{project}}-A-…): файлы — через Storage; одна картинка на пользователя.
**Отвергнуто:**
- Spatie Media Library — новая зависимость ради одного поля.
- Хранение в БД (blob) — нагрузка на БД, нет CDN.
**Влияние:** миграция `add_avatar_path_to_users_table`, статья «Модель данных» обновлена.
```

## [AGENT:BLOCKED]

После комментария: `php scripts/yt.php release <ID> --state=Blocked`.

```markdown
[AGENT:BLOCKED]
**Что мешает:** критерий приёмки требует «аватар по умолчанию», но не сказано какой.
**Что нужно от человека:** выбрать вариант и ответить комментарием:
1. Инициалы на цветном фоне (уже есть хук `use-initials`).
2. Статичная картинка `public/images/avatar-default.png`.
**Что уже сделано:** коммиты `abc1234` (миграция), ветка `epic/{{project}}-10-profile-page`.
**Как продолжить:** ответить комментарием и вернуть State в `Ready`.
```

## [AGENT:DONE]

```markdown
[AGENT:DONE]
**Сделано:**
- Action `UpdateUserAvatar`, джоб `ProcessUserAvatar` (очередь `media`), маршрут `user-avatar.update`.
**Коммиты:** `abc1234`, `def5678` (ветка `epic/{{project}}-10-profile-page`).
**Как проверить:**
- `scripts/run-tests.sh --filter=Avatar`
- Вручную: /settings/profile → загрузить PNG → превью появляется после обработки очереди.
**Тесты/линтеры:** `scripts/run-tests.sh` — passed (N tests), pint/phpstan — passed.
**Что осталось / смежная работа:** {{project}}-57 (создана: ограничение размера файла в конфиге).
```

## [AGENT:RELEASE]

```markdown
[AGENT:RELEASE]
**Причина:** задача возвращена в Ready — нашёлся конфликт по файлам с {{project}}-44, добавлена зависимость.
```
