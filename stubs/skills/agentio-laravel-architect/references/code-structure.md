# Структура кода: доменные неймспейсы (умолчание agentio)

Это умолчание agentio для проектов без своей раскладки: код раскладывается по доменам, а не по видам классов. Если раскладка задана в `CLAUDE.md`/`AGENTS.md`, `.ai/guidelines`, `.ai/rules` или устойчиво видна в коде (например, «Actions live in `app/Actions`»), действует она (SKILL.md, «Структура кода»); тогда из этого файла применяются правила про тесты и «Что учесть в Laravel» для уже выбранных доменов. Выбор закрепляет ADR-001 ({{kb.adr.001}}), по нему работают разработчики и ревьюер. Переход на домены в проекте с другой конвенцией — только после ответа человека.

## Домен

- Домен — модуль «Системной аналитики» ({{kb.analysis}}). Имя неймспейса — английское название модуля в PascalCase (`Users`, `Billing`, `Catalog`). Оно записывается в «Архитектура модуля → Код модуля» статьи модуля и в «Модули в коде» обзора ({{kb.architecture.overview}}).
- Подобласть большого модуля может стать вложенным неймспейсом (`App\Billing\Invoices\…`), это решение модуля `AD-n`.
- Код, которым пользуются несколько доменов (базовые классы, общие трейты, value objects, сквозная инфраструктура), кладётся в общий неймспейс `App\Shared\<Вид>\…` (точное имя закрепляет ADR-001). Если код нужен только одному домену, он живёт в этом домене. Класс не переезжает в `Shared` «на будущее».

## Схема

`App\<Домен>\<Вид>\<Класс>`. Внутри домена каталоги по виду называются так же, как в Laravel:

| Вид | Пример |
|---|---|
| Модель | `App\Users\Models\User` |
| Action | `App\Users\Actions\UpdateUserAvatar` |
| Сервис, адаптер внешней системы | `App\Users\Services\AvatarStorage`, `App\Billing\Integrations\Stripe\Client` |
| Контроллер | `App\Users\Http\Controllers\AvatarController` |
| Form Request | `App\Users\Http\Requests\UpdateUserAvatarRequest` |
| API Resource | `App\Users\Http\Resources\UserResource` |
| Middleware домена | `App\Users\Http\Middleware\EnsureProfileCompleted` |
| Policy | `App\Users\Policies\UserPolicy` |
| Джоб | `App\Users\Jobs\ProcessUserAvatar` |
| Событие, слушатель | `App\Users\Events\UserRegistered`, `App\Users\Listeners\SendWelcomeEmail` |
| Уведомление, письмо | `App\Users\Notifications\…`, `App\Users\Mail\…` |
| Консольная команда | `App\Users\Console\Commands\PruneUnverifiedUsers` |
| Enum, DTO, исключение | `App\Users\Enums\…`, `App\Users\Data\…`, `App\Users\Exceptions\…` |

Представления и страницы фронтенда тоже группируются по домену: `resources/views/users/…`, `resources/js/pages/users/…`. Регистр и разделитель берутся из конвенции проекта.

## Исключения: плоское по умолчанию в Laravel

Остаётся на своих местах:

- `database/migrations`, `database/factories`, `database/seeders`;
- `config`, `routes`, `lang`, `bootstrap`, `public`;
- каркас приложения: `App\Providers`, базовый `App\Http\Controllers\Controller`, глобальные middleware из `bootstrap/app.php` (например, `HandleInertiaRequests`);
- код, который пакет публикует и ищет по своему пути: `App\Actions\Fortify\*`, опубликованные провайдеры и стабы пакетов.

Файлы маршрутов можно разделить по доменам (`routes/web/users.php`, подключаемый из `routes/web.php`), это решение ADR-001.

## Тесты зеркалят неймспейс тестируемого класса

`App\<путь>\<Класс>` → `tests/<Набор>/<путь>/<Класс>Test.php`. Набор — `Feature`, `Unit` или `Browser` (как настроено в проекте) — выбирается по скиллу `testing-best-practices` и соседним тестам: код, использующий фреймворк (Eloquent, фасады, HTTP, очереди), — `Feature`; `Unit` — только логика без фреймворка (расчёты, value objects). Если тестовые классы объявляют неймспейс, он `Tests\<Набор>\<путь>`.

| Класс | Тест |
|---|---|
| `App\Users\Actions\UpdateUserAvatar` | `tests/Feature/Users/Actions/UpdateUserAvatarTest.php` |
| `App\Users\Http\Controllers\AvatarController` | `tests/Feature/Users/Http/Controllers/AvatarControllerTest.php` |
| `App\Users\Jobs\ProcessUserAvatar` | `tests/Feature/Users/Jobs/ProcessUserAvatarTest.php` |

- Один тестовый файл на класс. Сценарий, который проходит через несколько классов (HTTP-запрос, команда, джоб, вебхук), лежит в файле класса-точки входа: маршрут проверяется в тесте своего контроллера.
- Не допускаются плоские `tests/Feature/*Test.php` и каталоги «по фиче», которые не совпадают с неймспейсом класса.
- Тесты кода из исключений (миграции, фабрики, сиды) и архитектурные тесты на весь проект кладутся по конвенции проекта.

## Что учесть в Laravel

Автообнаружение Laravel рассчитано на плоские каталоги. Как проект это решает, закрепляет ADR-001 при появлении первого домена:

- **Фабрики.** Имя фабрики по умолчанию угадывается только для `App\Models\*`. Для `App\Users\Models\User` Laravel будет искать `Database\Factories\Users\Models\UserFactory`. Фабрика остаётся `Database\Factories\UserFactory`, поэтому модель объявляет её через `#[UseFactory(UserFactory::class)]` (или `newFactory()`), а фабрика объявляет модель через `#[UseModel(User::class)]` или `protected $model`. Атрибуты используй, только если они есть в установленной версии Laravel.
- **Policy.** Для `App\<Домен>\Models\<Модель>` политика `App\<Домен>\Policies\<Модель>Policy` находится автоматически. В остальных случаях используй `#[UsePolicy]` или `Gate::policy()`.
- **Слушатели событий.** Обнаружение принимает glob: `->withEvents(discover: [app_path('*/Listeners')])` в `bootstrap/app.php`.
- **Консольные команды.** `->withCommands([...])` принимает только существующие каталоги, glob там не работает. Каталог каждого домена (`app_path('Users/Console/Commands')`) добавляется явно, либо команды регистрируются в сервис-провайдере.
- **Пользователь.** При переносе `User` нужно поменять `config/auth.php` (`providers.users.model`) и все ссылки на `App\Models\User`.
- **Полиморфные связи.** Для доменных моделей нужен `Relation::enforceMorphMap()`, чтобы в БД хранились короткие имена, а не неймспейсы. Тогда перенос класса не потребует миграции данных.
- **Генераторы.** `php artisan make:* 'App\Users\Actions\UpdateUserAvatar' --no-interaction` с полным именем класса (есть проектный генератор вида, например `make:action`, — он). Короткое имя кладёт класс в каталог генератора по умолчанию. Тесты создаются так: `php artisan make:test Users/Http/Controllers/AvatarControllerTest --pest --no-interaction` (`--unit` для Unit), без имени набора в пути.

## Существующий плоский код

- Плоский код с устойчивой конвенцией — это конвенция проекта, а не отступление (Consistency First в `laravel-best-practices` и `testing-best-practices`, guidelines Boost). Новые классы кладутся туда же.
- Перевод на домены и перенос классов — только после ответа человека (`[AGENT:BLOCKED]` архитектора): решение модуля `AD-n` и отдельный первый шаг «Порядка реализации», конфликтующий со всеми задачами домена. Тесты переносятся только в этом шаге — guideline Pest запрещает удалять тесты без одобрения.
- Если человек выбрал «домены только для нового кода», оставшийся плоский код перечисляется в «Архитектура: обзор» со ссылкой на ADR-001.
