# Плагины Saloon

Ставить только при необходимости, отдельной задачей (`composer require saloonphp/<плагин> --no-interaction`). Версии под Saloon v4: `pagination-plugin ^2`, `rate-limit-plugin ^2`, `laravel-plugin ^5` (Laravel 12.39+/13), `cache-plugin ^3`. Сверяй с `vendor/saloonphp/<плагин>/src`.

## Пагинация (`saloonphp/pagination-plugin`)

```php
use Saloon\Http\Request;
use Saloon\Http\Response;
use Saloon\PaginationPlugin\Contracts\HasPagination;
use Saloon\PaginationPlugin\Contracts\Paginatable;
use Saloon\PaginationPlugin\PagedPaginator;

final class AcmeConnector extends Connector implements HasPagination
{
    public function paginate(Request $request): PagedPaginator
    {
        return new class(connector: $this, request: $request) extends PagedPaginator
        {
            protected ?int $perPageLimit = 100;

            protected function isLastPage(Response $response): bool
            {
                return $response->json('meta.current_page') >= $response->json('meta.last_page');
            }

            protected function getPageItems(Response $response, Request $request): array
            {
                return $response->dto();   // или $response->json('data')
            }
        };
    }
}

final class ListOrders extends Request implements Paginatable { /* … */ }

foreach ($connector->paginate(new ListOrders)->items() as $order) { … }        // ленивые элементы всех страниц
$connector->paginate(new ListOrders)->setMaxPages(10)->collect();               // LazyCollection
```

- `PagedPaginator` — `page`/`per_page` в query; `OffsetPaginator` — `limit`/`offset` (`$perPageLimit` обязателен); `CursorPaginator` — `getNextCursor(Response $response): int|string`. Другие имена параметров — переопредели `applyPagination(Request $request): Request`.
- Всегда ограничивай число страниц или делай обработку пачками в джобах: «все страницы» сервиса могут быть миллионами записей.

## Лимиты (`saloonphp/rate-limit-plugin`)

```php
use Illuminate\Support\Facades\Cache;
use Saloon\RateLimitPlugin\Contracts\RateLimitStore;
use Saloon\RateLimitPlugin\Limit;
use Saloon\RateLimitPlugin\Stores\LaravelCacheStore;
use Saloon\RateLimitPlugin\Traits\HasRateLimits;

final class AcmeConnector extends Connector
{
    use HasRateLimits;

    protected function resolveLimits(): array
    {
        return [
            Limit::allow(30)->everySeconds(1),
            Limit::allow(10000)->everyDay(),
        ];
    }

    protected function resolveRateLimitStore(): RateLimitStore
    {
        return new LaravelCacheStore(Cache::store());   // общий для всех воркеров (redis)
    }
}
```

- Превышение → `Saloon\RateLimitPlugin\Exceptions\RateLimitReachedException` (`$e->getLimit()->getRemainingSeconds()`): в джобе — `release()` на это время; `Limit::allow(...)->sleep()` — ждать вместо исключения (только вне веб-запросов).
- Ответ 429 сервиса тоже учитывается (`$detectTooManyAttempts`).
- Для джобов Laravel альтернатива — middleware `RateLimited` / `ThrottlesExceptions`; не дублируй два механизма на одном вызове без нужды.

## Laravel-плагин (`saloonphp/laravel-plugin`)

- Фасад `Saloon\Laravel\Facades\Saloon`: `Saloon::fake([...])`, `Saloon::assertSent(...)`, `assertNotSent`, `assertSentCount`, `assertNothingSent` — то же, что `MockClient::global()`.
- События `SendingSaloonRequest` / `SentSaloonRequest` (логирование, метрики), интеграция с Telescope, Pulse и Nightwatch.
- Генераторы `php artisan saloon:connector|request|response|plugin|auth` пишут в `config('saloon.integrations_path')` (по умолчанию `app/Http/Integrations`) — используй, только если это раскладка проекта (ADR-001), иначе создавай классы руками.

## Кэш ответов (`saloonphp/cache-plugin`)

Только для справочных GET-запросов с явным TTL: `implements Cacheable`, `use HasCaching`, `resolveCacheDriver()` → `new LaravelCacheDriver(Cache::store())`, `cacheExpiryInSeconds()`. Не кэшируй ответы с персональными данными и изменяющие запросы.
