# Структура кода: каталоги Laravel, внутри — домены (умолчание agentio)

Код раскладывается по стандартным каталогам Laravel по виду класса (`App\Actions`, `App\Http\Controllers`, `App\Models`, `App\Services`, `App\Jobs`, …), а **внутри каталога вида — по доменам**: `App\Actions\Listings\PublishListing`, `App\Http\Controllers\Auth\LoginController`, `App\Models\Articles\Article`. Каталогов верхнего уровня по доменам (`app/Users`, `app/Billing`, `App\Users\Actions\…`) нет. Если раскладка задана в `CLAUDE.md`/`AGENTS.md`, `.ai/guidelines` или `.ai/rules`, действует она (SKILL.md, «Структура кода»); тогда из этого файла применяются правила про тесты и «Что учесть в Laravel». Выбор закрепляет ADR-001 ({{kb.adr.001}}), по нему работают разработчики и ревьюер.

## Домен

- Домен — модуль «Системной аналитики» ({{kb.analysis}}) или его устойчивая подобласть. Имя подкаталога — английское название в PascalCase (`Auth`, `Users`, `Listings`, `Articles`, `Billing`). Оно записывается в «Архитектура модуля → Код модуля» статьи модуля и в «Модули в коде» обзора ({{kb.architecture.overview}}). У модуля может быть несколько доменов (модуль «Пользователи» → `Auth` и `Users`), один домен не делится между модулями.
- Имя домена одинаково во всех каталогах вида: если контроллеры в `App\Http\Controllers\Listings`, то и Actions — в `App\Actions\Listings`, а модели — в `App\Models\Listings`.
- Подобласть большого домена может стать вложенным подкаталогом (`App\Actions\Billing\Invoices\…`), это решение модуля `AD-n`.
- Код, которым пользуются несколько доменов (базовые классы, общие трейты, value objects, сквозная инфраструктура), лежит в каталоге своего вида без доменного подкаталога: `App\Models\Concerns\HasUuid`, `App\Enums\Currency`, `App\Http\Middleware\SetLocale`. Если код нужен только одному домену, он живёт в подкаталоге этого домена. Класс не выносится в общий код «на будущее».

## Схема

`App\<Каталог вида Laravel>\<Домен>\<Класс>`:

| Вид | Пример |
|---|---|
| Модель | `App\Models\Listings\Listing`, `App\Models\Articles\Article` |
| Action | `App\Actions\Listings\PublishListing`, `App\Actions\Auth\RegisterUser` |
| Сервис | `App\Services\Listings\ListingPriceCalculator` |
| Контроллер | `App\Http\Controllers\Auth\LoginController`, `App\Http\Controllers\Listings\ListingController` |
| Form Request | `App\Http\Requests\Listings\StoreListingRequest` |
| API Resource | `App\Http\Resources\Listings\ListingResource` |
| Middleware домена | `App\Http\Middleware\Listings\EnsureListingIsPublished` |
| Policy | `App\Policies\Listings\ListingPolicy` |
| Джоб | `App\Jobs\Listings\ProcessListingPhotos` |
| Событие, слушатель | `App\Events\Listings\ListingPublished`, `App\Listeners\Listings\NotifySubscribers` |
| Уведомление, письмо | `App\Notifications\Listings\…`, `App\Mail\Listings\…` |
| Консольная команда | `App\Console\Commands\Listings\ExpireListings` |
| Enum, DTO, исключение | `App\Enums\Listings\…`, `App\Data\Listings\…`, `App\Exceptions\Listings\…` |

Интеграции с внешними API лежат в `App\Integrations\<Сервис>\…` (`App\Integrations\Stripe\StripeClient`, `App\Integrations\Cdek\Requests\CreateOrder`), тесты — `tests/Feature/Integrations/<Сервис>/…` (скилл `agentio-saloon`, «Где лежит код»): здесь роль домена играет сервис. Это правило действует и в проекте с собственной раскладкой.

Представления и страницы фронтенда тоже группируются по домену: `resources/views/listings/…`, `resources/js/pages/listings/…`. Регистр и разделитель берутся из конвенции проекта.

## Исключения: плоское по умолчанию в Laravel

Остаётся на своих местах:

- `database/migrations`, `database/seeders`; фабрики повторяют подкаталог модели (`Database\Factories\Listings\ListingFactory`, см. ниже);
- `config`, `routes`, `lang`, `bootstrap`, `public`;
- каркас приложения: `App\Providers`, базовый `App\Http\Controllers\Controller`, глобальные middleware из `bootstrap/app.php` (например, `HandleInertiaRequests`);
- модель `App\Models\User` из каркаса Laravel (на неё ссылаются `config/auth.php`, стартер-киты и пакеты) и её фабрика `Database\Factories\UserFactory`;
- код, который пакет публикует и ищет по своему пути: `App\Actions\Fortify\*`, опубликованные провайдеры и стабы пакетов.

Файлы маршрутов можно разделить по доменам (`routes/web/listings.php`, подключаемый из `routes/web.php`), это решение ADR-001.

## Тесты зеркалят неймспейс тестируемого класса

`App\<путь>\<Класс>` → `tests/<Набор>/<путь>/<Класс>Test.php`. Набор — `Feature`, `Unit` или `Browser` (как настроено в проекте) — выбирается по скиллу `testing-best-practices` и соседним тестам: код, использующий фреймворк (Eloquent, фасады, HTTP, очереди), — `Feature`; `Unit` — только логика без фреймворка (расчёты, value objects). Если тестовые классы объявляют неймспейс, он `Tests\<Набор>\<путь>`.

| Класс | Тест |
|---|---|
| `App\Actions\Listings\PublishListing` | `tests/Feature/Actions/Listings/PublishListingTest.php` |
| `App\Http\Controllers\Auth\LoginController` | `tests/Feature/Http/Controllers/Auth/LoginControllerTest.php` |
| `App\Jobs\Listings\ProcessListingPhotos` | `tests/Feature/Jobs/Listings/ProcessListingPhotosTest.php` |
| `App\Services\Listings\ListingPriceCalculator` (без фреймворка) | `tests/Unit/Services/Listings/ListingPriceCalculatorTest.php` |

- Один тестовый файл на класс. Сценарий, который проходит через несколько классов (HTTP-запрос, команда, джоб, вебхук), лежит в файле класса-точки входа: маршрут проверяется в тесте своего контроллера.
- Не допускаются плоские `tests/Feature/*Test.php` и каталоги «по фиче», которые не совпадают с неймспейсом класса.
- Тесты кода из исключений (миграции, фабрики, сиды) и архитектурные тесты на весь проект кладутся по конвенции проекта.

## Что учесть в Laravel

Автообнаружение Laravel работает с подкаталогами доменов без настройки:

- **Фабрики.** Для `App\Models\Listings\Listing` Laravel ищет `Database\Factories\Listings\ListingFactory`, а фабрика по своему имени находит модель — фабрика лежит в `database/factories/Listings/ListingFactory.php` с неймспейсом `Database\Factories\Listings`, атрибуты `#[UseFactory]`/`#[UseModel]` не нужны.
- **Policy.** Для `App\Models\Listings\Listing` политика `App\Policies\Listings\ListingPolicy` находится автоматически; `#[UsePolicy]` или `Gate::policy()` — только для отступлений от этой схемы.
- **Слушатели событий и консольные команды.** `app/Listeners` и `app/Console/Commands` сканируются рекурсивно: классы в доменных подкаталогах регистрируются сами.
- **Полиморфные связи.** Для моделей в подкаталогах нужен `Relation::enforceMorphMap()`, чтобы в БД хранились короткие имена, а не неймспейсы. Тогда перенос класса не потребует миграции данных.
- **Генераторы.** Имя с подкаталогом домена: `php artisan make:model Listings/Listing -f --no-interaction`, `php artisan make:controller Listings/ListingController --no-interaction`, `php artisan make:request Listings/StoreListingRequest --no-interaction` (есть проектный генератор вида, например `make:action`, — он, с тем же `Listings/…`). Тесты: `php artisan make:test Actions/Listings/PublishListingTest --pest --no-interaction` (`--unit` для Unit), без имени набора в пути.

## Существующий код без доменных подкаталогов

- Классы, которые уже лежат прямо в каталоге вида (`App\Actions\CreateListing`, `App\Models\Listing`), не переносятся сами собой: перенос — только после ответа человека (`[AGENT:BLOCKED]` архитектора), решением модуля `AD-n` и отдельным первым шагом «Порядка реализации», конфликтующим со всеми задачами домена. Тесты переносятся только в этом шаге — guideline Pest запрещает удалять тесты без одобрения.
- Новые классы кладутся в доменный подкаталог, даже если соседние классы домена ещё лежат плоско; доменный подкаталог внутри существующего каталога вида — не новый базовый каталог.
- Если человек выбрал «домены только для нового кода», оставшийся плоский код перечисляется в «Архитектура: обзор» со ссылкой на ADR-001.
- Код, разложенный по доменам на верхнем уровне (`app/Users/Actions/…`, прежнее умолчание agentio), — та же ситуация: новые классы — в каталоги вида Laravel с доменным подкаталогом, перенос старых — только решением человека.
