---
name: agentio-saloon
description: Интеграции проекта {{project}} с внешними HTTP API через Saloon (saloonphp/saloon) — когда у сервиса нет готового поддерживаемого SDK (Telegram Bot API, VK API, платёжные и логистические сервисы, CRM, любой REST/JSON API) — коннектор и запросы, аутентификация, таймауты и ретраи, ошибки, DTO, пагинация, лимиты, OAuth2, вебхуки рядом с клиентом, тесты через MockClient. Применять при проектировании и реализации любого вызова внешнего API, при выборе «SDK, Saloon или Http::» и при ревью кода интеграции.
---

# Saloon: интеграции без SDK

Правило проекта: **есть поддерживаемый SDK сервиса — используем SDK** (его установка — решение человека, раздел «Вопросы к человеку» скилла agentio-youtrack-workflow); **SDK нет — клиент на Saloon**, а не голый `Http::` и не самописный Guzzle. Saloon даёт то, что иначе пишут руками в каждом адаптере: класс на каждый запрос, общую конфигурацию в коннекторе, ретраи, исключения по статусам, DTO, моки в тестах. Архитектура интеграции (адаптер, таймауты, идемпотентность, вебхуки, деградация) — `../agentio-laravel-architect/references/external-integrations.md`; этот скилл — как её реализовать на Saloon.

Справочники:
- [references/connectors-and-requests.md](references/connectors-and-requests.md) — коннектор, запросы, тела, аутентификация, ответы и DTO, ошибки и ретраи, middleware, OAuth2;
- [references/testing.md](references/testing.md) — тесты: MockClient, фикстуры, проверки отправленного, запрет реальных запросов;
- [references/plugins.md](references/plugins.md) — пагинация, лимиты (rate limit), Laravel-плагин.

## Версия и установка

1. Версия — `mcp__laravel-boost__application-info` или `composer show saloonphp/saloon`. Справочники написаны по **Saloon v4** (`saloonphp/saloon ^4`); `search-docs` Boost Saloon не знает — сверяй API с исходниками установленной версии в `vendor/saloonphp/saloon/src` (и плагинов в `vendor/saloonphp/*`), а при доступе к сети — с https://docs.saloon.dev. API, которого нет в установленной версии, не используй.
2. Нет Saloon в `composer.json` — его ставит отдельная первая TASK эпика (её планирует архитектор, решение — ADR «Интеграции без SDK — через Saloon»): `composer require saloonphp/saloon --no-interaction` (не `--dev`: это код приложения). Агентам из всех зависимостей разрешены только пакеты `saloonphp/*`; коммить `composer.json` и `composer.lock` в той же задаче. Плагины ставь, только когда нужны: `saloonphp/pagination-plugin` (пагинация), `saloonphp/rate-limit-plugin` (лимиты), `saloonphp/laravel-plugin` (фасад `Saloon::fake()`, события, интеграция с Telescope/Pulse/Nightwatch — не обязателен).

## Где лежит код

По правилу раскладки ADR-001 (конвенция проекта; по умолчанию — домен-владелец интеграции). Генераторы `saloon:*` Laravel-плагина кладут классы в `app/Http/Integrations` — используй их, только если это и есть раскладка проекта; иначе создавай классы руками по полному имени:

```
App\<Домен>\Integrations\<Сервис>\<Сервис>Connector          коннектор: base URL, auth, таймауты, ретраи
App\<Домен>\Integrations\<Сервис>\Requests\<Действие>        по классу на эндпоинт: GetOrder, CreatePayment
App\<Домен>\Integrations\<Сервис>\Data\<Сущность>            DTO ответов (readonly)
App\<Домен>\Integrations\<Сервис>\<Сервис>Exception          свои исключения (опционально)
App\<Домен>\Integrations\<Сервис>\<Сервис>Client             адаптер для остального кода (опционально, когда операций много)
tests/…/Integrations/<Сервис>/…                               по правилу тестов ADR-001
config/services.php → '<сервис>' => ['url' => env(...), 'token' => env(...), 'timeout' => …]
```

Остальной код зовёт коннектор (или адаптер) и получает DTO или своё исключение — HTTP, статусы и JSON наружу не выходят. Коннектор регистрируется в контейнере (`AppServiceProvider` или провайдер домена): `$this->app->bind(ShopConnector::class, fn () => new ShopConnector(config('services.shop.url'), config('services.shop.token')))` — так его подменяет тест и не нужен `config()` внутри класса.

## Порядок работы

1. Документация сервиса: базовый URL, аутентификация, формат ошибок, лимиты, пагинация, идемпотентность (ключ идемпотентности для изменяющих запросов), вебхуки. Есть скилл платформы (`.claude/skills/<платформа>-development`) — сначала он.
2. Коннектор: `resolveBaseUrl()`, заголовки по умолчанию, `defaultAuth()`, `HasTimeout` (`$connectTimeout`, `$requestTimeout` — явно), ретраи (`$tries`, `$retryInterval`, `$useExponentialBackoff`, `handleRetry()` — только временные ошибки), `AlwaysThrowOnErrors` или явная проверка ответа.
3. Запрос на каждый эндпоинт: метод, `resolveEndpoint()`, параметры — в конструкторе (`readonly`), тело — `HasBody` + `HasJsonBody`/`HasFormBody`/`HasMultipartBody`, `createDtoFromResponse()`.
4. Ошибки: статусы сервиса → понятные исключения (`ClientException`/`ServerException`/`FatalRequestException` Saloon или свои), без токенов и персональных данных в сообщениях и логах. Сервис отвечает 200 с ошибкой в теле (`{"ok": false}`) — `hasRequestFailed()` в коннекторе.
5. Вызовы: изменяющие и медленные — в джобах (ретраи очереди, `$backoff`); не перемножай ретраи Saloon и очереди без расчёта; таймаут джоба больше суммы таймаутов попыток и меньше `retry_after` соединения очереди.
6. Тесты — [references/testing.md](references/testing.md): каждый запрос и сценарии успех / 4xx / 5xx / 429 / таймаут соединения / неожиданный формат; реальные запросы в тестах запрещены.

## Анти-паттерны

- `Http::` или `new GuzzleHttp\Client` для сервиса, у которого есть Saloon-коннектор в проекте, или рядом с Saloon.
- Абсолютный URL в `resolveEndpoint()`: в v4 запрещён (защита от SSRF и утечки токена); другой хост — другой коннектор.
- Секреты в коде, в `defaultQuery()` без нужды, в исключениях и логах; `config()`/`env()` внутри запросов.
- Ретраи на 4xx (кроме 408/429) и на неидемпотентных запросах без ключа идемпотентности.
- `$response->json()` без проверки статуса и формата: отсутствие поля — понятное исключение, а не `null` в глубине кода.
- `Http::fake()` в тестах Saloon-клиента: он не перехватывает запросы Saloon (свой Guzzle-sender).

## Чек-лист

- [ ] SDK нет (или он заброшен) — поэтому Saloon; решение записано в «Архитектуре» фичи или ADR.
- [ ] Коннектор: base URL и секреты из `config/services.php`, явные таймауты, ретраи только для временных ошибок, ошибки → исключения.
- [ ] Класс на каждый эндпоинт, DTO для ответов, наружу не выходит HTTP.
- [ ] Изменяющие вызовы — в джобах, с ключом идемпотентности, если сервис его поддерживает.
- [ ] Тесты на MockClient с успехом и ошибками; реальные запросы запрещены; проверено, что отправлено.
