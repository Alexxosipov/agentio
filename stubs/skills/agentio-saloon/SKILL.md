---
name: agentio-saloon
description: Интеграции проекта {{project}} с внешними HTTP API — правило «есть поддерживаемый SDK — SDK, нет — клиент на Saloon (saloonphp/saloon)», весь код интеграции в неймспейсе App\Integrations\<Сервис> (Telegram Bot API, VK API, платёжные и логистические сервисы, CRM, любой REST/JSON API) — коннектор и запросы, аутентификация, таймауты и ретраи, ошибки, DTO, пагинация, лимиты, OAuth2, вебхуки рядом с клиентом, тесты через MockClient. Применять при проектировании и реализации любого вызова внешнего API, при выборе «SDK, Saloon или Http::» и при ревью кода интеграции.
---

# Интеграции с внешними API: SDK или Saloon, App\Integrations\<Сервис>

Saloon даёт то, что иначе пишут руками в каждом адаптере: класс на каждый запрос, общую конфигурацию в коннекторе, ретраи, исключения по статусам, DTO, моки в тестах. Архитектура интеграции (адаптер, таймауты, идемпотентность, вебхуки, деградация) — `../agentio-laravel-architect/references/external-integrations.md`; этот скилл — правило выбора клиента, раскладка и реализация на Saloon.

## Правило проекта: SDK или Saloon

Для каждого внешнего сервиса (HTTP API другой компании или системы) клиент выбирается так — до кода, в «Архитектуре» фичи или ADR:

1. **Есть готовый поддерживаемый PHP SDK — используем SDK.** Поддерживаемый — официальный SDK сервиса или общепринятый пакет сообщества с релизами за последний год, поддержкой версий PHP и Laravel проекта и покрытием нужных операций. SDK, которого нет в `composer.json`, — новая зависимость: её установка — решение человека (`[AGENT:BLOCKED]` с вариантами «SDK <пакет>» и «клиент на Saloon» и рекомендацией, раздел «Вопросы к человеку» скилла agentio-youtrack-workflow); до ответа проектируй то, что от клиента не зависит. Вокруг SDK — тонкий адаптер в `App\Integrations\<Сервис>`: типы и исключения SDK наружу не выходят.
2. **Готового SDK нет (или он заброшен, не поддерживает версии проекта, не покрывает нужные операции) — пишем интеграцию на Saloon** (`saloonphp/saloon`) по этому скиллу. Не голый `Http::`/`Http::macro`, не `new GuzzleHttp\Client`, не `curl_*`/`file_get_contents`, не самописный «базовый API-клиент»: Saloon заранее одобрен владельцем проекта, вопрос человеку не нужен.
3. **Почему так решено** записывается одной строкой в «Архитектуре» фичи («SDK нет → Saloon», «официальный SDK `vendor/package` → адаптер»); первая интеграция на Saloon — ADR «Интеграции без SDK — через Saloon».

`Http::` допустим только для разового запроса без сервиса за ним (скачать файл по публичной ссылке) — не для API.

Справочники:
- [references/connectors-and-requests.md](references/connectors-and-requests.md) — коннектор, запросы, тела, аутентификация, ответы и DTO, ошибки и ретраи, middleware, OAuth2;
- [references/testing.md](references/testing.md) — тесты: MockClient, фикстуры, проверки отправленного, запрет реальных запросов;
- [references/plugins.md](references/plugins.md) — пагинация, лимиты (rate limit), Laravel-плагин.

## Версия и установка

1. Версия — `mcp__laravel-boost__application-info` или `composer show saloonphp/saloon`. Справочники написаны по **Saloon v4** (`saloonphp/saloon ^4`); `search-docs` Boost Saloon не знает — сверяй API с исходниками установленной версии в `vendor/saloonphp/saloon/src` (и плагинов в `vendor/saloonphp/*`), а при доступе к сети — с https://docs.saloon.dev. API, которого нет в установленной версии, не используй.
2. Нет Saloon в `composer.json` — его ставит отдельная первая TASK эпика (её планирует архитектор, решение — ADR «Интеграции без SDK — через Saloon»): `composer require saloonphp/saloon --no-interaction` (не `--dev`: это код приложения). Агентам из всех зависимостей разрешены только пакеты `saloonphp/*`; коммить `composer.json` и `composer.lock` в той же задаче. Плагины ставь, только когда нужны: `saloonphp/pagination-plugin` (пагинация), `saloonphp/rate-limit-plugin` (лимиты), `saloonphp/laravel-plugin` (фасад `Saloon::fake()`, события, интеграция с Telescope/Pulse/Nightwatch — не обязателен).

## Где лежит код: `App\Integrations\<Сервис>`

**Каждая интеграция с внешним API — на Saloon или адаптер над SDK — живёт в своём неймспейсе `App\Integrations\<Сервис>` (каталог `app/Integrations/<Сервис>`).** `<Сервис>` — имя внешнего API в PascalCase: `Telegram`, `Vk`, `YooKassa`, `Cdek`, `AmoCrm`, `OpenAi`. Это правило проекта при любой раскладке остального кода (доменной или плоской, ADR-001): интеграцию не кладут в `App\<Домен>\…`, `App\Services\…`, `App\Shared\…` или `App\Http\Integrations` (каталог генераторов Laravel-плагина Saloon); один сервис — один неймспейс, даже если им пользуются несколько доменов. Доменный код (Actions, джобы, слушатели) зовёт клиента из `App\Integrations\<Сервис>` и получает DTO или исключение интеграции.

```
App\Integrations\<Сервис>\<Сервис>Connector          коннектор: base URL, auth, таймауты, ретраи (Saloon)
App\Integrations\<Сервис>\Requests\<Действие>        по классу на эндпоинт: GetOrder, CreatePayment (Saloon)
App\Integrations\<Сервис>\Data\<Сущность>            DTO ответов (readonly)
App\Integrations\<Сервис>\<Сервис>Exception          исключения интеграции (опционально — свои подклассы)
App\Integrations\<Сервис>\<Сервис>Client             адаптер для остального кода: над коннектором (когда операций много) или над SDK
App\Integrations\<Сервис>\Webhooks\…                 проверка подписи и разбор входящих вебхуков в DTO (контроллер — по раскладке ADR-001)
tests/Feature/Integrations/<Сервис>/…                зеркально: App\Integrations\Acme\Requests\GetOrder → tests/Feature/Integrations/Acme/Requests/GetOrderTest.php
config/services.php → '<сервис>' => ['url' => env(...), 'token' => env(...), 'timeout' => …]
```

Генераторы `saloon:*` Laravel-плагина по умолчанию пишут в `app/Http/Integrations`: создавай классы руками по полному имени (или задай `'integrations_path' => app_path('Integrations')` в `config/saloon.php`, если плагин установлен). Остальной код зовёт коннектор (или адаптер) и получает DTO или своё исключение — HTTP, статусы и JSON наружу не выходят. Коннектор регистрируется в контейнере (`AppServiceProvider` или провайдер домена): `$this->app->bind(ShopConnector::class, fn () => new ShopConnector(config('services.shop.url'), config('services.shop.token')))` — так его подменяет тест и не нужен `config()` внутри класса.

## Порядок работы

1. Документация сервиса: базовый URL, аутентификация, формат ошибок, лимиты, пагинация, идемпотентность (ключ идемпотентности для изменяющих запросов), вебхуки. Есть скилл платформы (`.claude/skills/<платформа>-development`) — сначала он.
2. Коннектор: `resolveBaseUrl()`, заголовки по умолчанию, `defaultAuth()`, `HasTimeout` (`$connectTimeout`, `$requestTimeout` — явно), ретраи (`$tries`, `$retryInterval`, `$useExponentialBackoff`, `handleRetry()` — только временные ошибки), `AlwaysThrowOnErrors` или явная проверка ответа.
3. Запрос на каждый эндпоинт: метод, `resolveEndpoint()`, параметры — в конструкторе (`readonly`), тело — `HasBody` + `HasJsonBody`/`HasFormBody`/`HasMultipartBody`, `createDtoFromResponse()`.
4. Ошибки: статусы сервиса → понятные исключения (`ClientException`/`ServerException`/`FatalRequestException` Saloon или свои), без токенов и персональных данных в сообщениях и логах. Сервис отвечает 200 с ошибкой в теле (`{"ok": false}`) — `hasRequestFailed()` в коннекторе.
5. Вызовы: изменяющие и медленные — в джобах (ретраи очереди, `$backoff`); не перемножай ретраи Saloon и очереди без расчёта; таймаут джоба больше суммы таймаутов попыток и меньше `retry_after` соединения очереди.
6. Тесты — [references/testing.md](references/testing.md): каждый запрос и сценарии успех / 4xx / 5xx / 429 / таймаут соединения / неожиданный формат; реальные запросы в тестах запрещены.

## Анти-паттерны

- `Http::`, `new GuzzleHttp\Client` или `curl_*` для API внешнего сервиса: есть SDK — SDK, нет — Saloon.
- Интеграция вне `App\Integrations\<Сервис>`: в домене, в `App\Services`, в `App\Http\Integrations`; вызовы SDK или коннектора напрямую из контроллеров и моделей, минуя адаптер.
- Абсолютный URL в `resolveEndpoint()`: в v4 запрещён (защита от SSRF и утечки токена); другой хост — другой коннектор.
- Секреты в коде, в `defaultQuery()` без нужды, в исключениях и логах; `config()`/`env()` внутри запросов.
- Ретраи на 4xx (кроме 408/429) и на неидемпотентных запросах без ключа идемпотентности.
- `$response->json()` без проверки статуса и формата: отсутствие поля — понятное исключение, а не `null` в глубине кода.
- `Http::fake()` в тестах Saloon-клиента: он не перехватывает запросы Saloon (свой Guzzle-sender).

## Чек-лист

- [ ] Клиент выбран по правилу: поддерживаемый SDK — SDK (установка одобрена человеком), SDK нет или он заброшен — Saloon; решение записано в «Архитектуре» фичи или ADR.
- [ ] Весь код интеграции — в `App\Integrations\<Сервис>`, тесты — в `tests/Feature/Integrations/<Сервис>`.
- [ ] Коннектор: base URL и секреты из `config/services.php`, явные таймауты, ретраи только для временных ошибок, ошибки → исключения.
- [ ] Класс на каждый эндпоинт, DTO для ответов, наружу не выходит HTTP.
- [ ] Изменяющие вызовы — в джобах, с ключом идемпотентности, если сервис его поддерживает.
- [ ] Тесты на MockClient с успехом и ошибками; реальные запросы запрещены; проверено, что отправлено.
