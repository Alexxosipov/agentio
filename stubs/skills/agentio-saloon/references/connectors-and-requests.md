# Коннекторы и запросы (Saloon v4)

Сверяй с `vendor/saloonphp/saloon/src` установленной версии.

## Содержание
- Коннектор
- Запросы и тела
- Аутентификация
- Ответы и DTO
- Ошибки и ретраи
- Middleware и отладка
- OAuth2

## Коннектор

```php
<?php

declare(strict_types=1);

namespace App\Shop\Integrations\Acme;

use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Http\Auth\TokenAuthenticator;
use Saloon\Http\Connector;
use Saloon\Http\Request;
use Saloon\Http\Response;
use Saloon\Traits\Plugins\AcceptsJson;
use Saloon\Traits\Plugins\AlwaysThrowOnErrors;
use Saloon\Traits\Plugins\HasTimeout;

final class AcmeConnector extends Connector
{
    use AcceptsJson;          // Accept: application/json
    use AlwaysThrowOnErrors;  // 4xx/5xx → RequestException (без него: $response->failed() / ->throw())
    use HasTimeout;

    protected int $connectTimeout = 5;   // секунды
    protected int $requestTimeout = 15;

    public ?int $tries = 3;                     // всего попыток
    public ?int $retryInterval = 500;           // мс
    public ?bool $useExponentialBackoff = true;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
    ) {}

    public function resolveBaseUrl(): string
    {
        return rtrim($this->baseUrl, '/');
    }

    protected function defaultHeaders(): array
    {
        return ['User-Agent' => 'my-app'];
    }

    protected function defaultAuth(): TokenAuthenticator
    {
        return new TokenAuthenticator($this->token); // Authorization: Bearer <token>
    }

    // Повторять только временные ошибки: соединение, 408, 429, 5xx.
    public function handleRetry(FatalRequestException|RequestException $exception, Request $request): bool
    {
        if ($exception instanceof FatalRequestException) {
            return true;
        }

        return in_array($exception->getResponse()->status(), [408, 429, 500, 502, 503, 504], true);
    }
}
```

- Свойства ретраев (`$tries`, `$retryInterval`, `$useExponentialBackoff`, `$throwOnMaxTries`) можно задать и у запроса — у запроса приоритет. При `$tries > 1` неуспешный ответ всегда превращается в исключение, после последней попытки оно выбрасывается (`$throwOnMaxTries = false` — вернуть последний ответ).
- Таймауты запроса: `use HasTimeout` и свойства в классе запроса (например, long polling).
- `defaultQuery()`, `defaultConfig()` (опции Guzzle) — по необходимости.
- Другой хост (CDN файлов, отдельный API загрузки) — отдельный коннектор: абсолютный URL в `resolveEndpoint()` запрещён (`$allowBaseUrlOverride = false` по умолчанию; включать только для доверенных URL).
- Токен в пути (`https://api.telegram.org/bot<token>/…`) — в `resolveBaseUrl()`, а тексты исключений транспорта (Guzzle включает URL) чистить перед логом.

## Запросы и тела

```php
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class CreatePayment extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        private readonly int $amount,       // в минимальных единицах
        private readonly string $orderId,
        private readonly string $idempotencyKey,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/payments';
    }

    protected function defaultHeaders(): array
    {
        return ['Idempotency-Key' => $this->idempotencyKey];
    }

    protected function defaultBody(): array
    {
        return ['amount' => $this->amount, 'order_id' => $this->orderId];
    }

    public function createDtoFromResponse(Response $response): Payment
    {
        return Payment::fromArray($response->json());
    }
}
```

- GET с параметрами: `protected function defaultQuery(): array { return ['status' => $this->status]; }`; путь с параметром: `'/orders/'.rawurlencode($this->id)`.
- Тела (`implements HasBody` + трейт): `HasJsonBody`, `HasFormBody` (x-www-form-urlencoded), `HasMultipartBody` (файлы: `defaultBody()` возвращает `[new MultipartValue(name: 'file', value: $contents, filename: 'voice.ogg'), new MultipartValue('model', 'whisper-1')]`), `HasXmlBody`, `HasStringBody`, `HasStreamBody`.
- Разовые значения при отправке: `$request->query()->add('page', 2)`, `$request->headers()->add(...)`, `$request->body()->merge([...])`.
- Отправка: `$connector->send($request)`; асинхронно — `sendAsync()`, пачкой с ограничением параллелизма — `$connector->pool($requests, concurrency: 5, responseHandler: …, exceptionHandler: …)->send()->wait()`.

## Аутентификация

`defaultAuth()` коннектора или запроса, либо `$connector->authenticate($authenticator)` на экземпляре:

| Схема | Класс |
|---|---|
| `Authorization: Bearer <token>` | `TokenAuthenticator($token)` (второй аргумент — префикс) |
| Basic | `BasicAuthenticator($user, $password)` |
| Ключ в заголовке (`X-Api-Key`) | `HeaderAuthenticator($token, 'X-Api-Key')` |
| Ключ в query | `QueryAuthenticator('api_key', $key)` |
| Несколько сразу | `MultiAuthenticator(...)` |
| Подпись запроса (HMAC и т.п.) | свой класс `implements Saloon\Contracts\Authenticator` с `set(PendingRequest $pendingRequest): void` |

## Ответы и DTO

`$response->status()`, `successful()`, `failed()`, `clientError()`, `serverError()`, `json('data.items', [])` (точечная нотация, по умолчанию — значение), `collect('data')`, `object()`, `body()`, `header('Retry-After')`, `dto()` (через `createDtoFromResponse`), `dtoOrFail()` (исключение при неуспешном ответе), `throw()`, `saveBodyToFile($path)` (файлы). `json()` выбрасывает `JsonException` на невалидном JSON — оборачивай в своё исключение.

DTO — `final readonly class` с именованным конструктором `fromArray(array $data)`, который проверяет обязательные поля и типы (нет поля → исключение с названием сервиса и поля, без значений секретов).

## Ошибки и ретраи

- Без `AlwaysThrowOnErrors` ответ 4xx/5xx возвращается как есть; `->throw()` превращает его в исключение. Исключения: `Saloon\Exceptions\Request\FatalRequestException` (нет ответа: DNS, таймаут, TLS), `RequestException` с потомками `ClientException`/`ServerException` и статусными `Statuses\NotFoundException`, `UnauthorizedException`, `ForbiddenException`, `UnprocessableEntityException`, `TooManyRequestsException`, `ServiceUnavailableException`, … — у `RequestException` есть `getResponse()`.
- Сервис сообщает об ошибке в теле при 200: переопредели `hasRequestFailed(Response $response): ?bool` в коннекторе (`return $response->json('ok') === false;`); своё исключение — `getRequestException(Response $response, ?Throwable $senderException): ?Throwable`.
- 429: уважай `Retry-After` — в джобе `release($seconds)`, в синхронном коде — `rate-limit-plugin` или отказ с понятным сообщением.
- Перехват в адаптере: `try { … } catch (FatalRequestException $e) { throw AcmeUnavailable::from($e); } catch (RequestException $e) { … }`.

## Middleware и отладка

```php
public function boot(PendingRequest $pendingRequest): void   // в коннекторе или запросе, на каждый запрос
{
    $pendingRequest->headers()->add('X-Request-Id', (string) Str::uuid());
}

$connector->middleware()->onRequest(fn (PendingRequest $pendingRequest) => …);
$connector->middleware()->onResponse(fn (Response $response) => …);
```

Логирование вызова (сервис, операция, длительность, статус, ID нашей сущности, без тел с секретами) — в `onResponse` или в адаптере. Отладка локально: `$connector->debug()` (не коммить).

## OAuth2

Коннектор с `use AuthorizationCodeGrant;` (или `ClientCredentialsGrant;`) и

```php
protected function defaultOauthConfig(): OAuthConfig
{
    return OAuthConfig::make()
        ->setClientId(config('services.acme.client_id'))
        ->setClientSecret(config('services.acme.client_secret'))
        ->setRedirectUri(route('acme.oauth.callback'))
        ->setDefaultScopes(['read'])
        ->setAuthorizeEndpoint('/oauth/authorize')
        ->setTokenEndpoint('/oauth/token');
}
```

`getAuthorizationUrl()` + `getState()` (сохрани state в сессии), `getAccessToken($code, $state, $expectedState)` → `OAuthAuthenticator` (хранить зашифрованным: `encrypted` cast), `refreshAccessToken($authenticator)` по `hasExpired()`; запросы — `$connector->authenticate($authenticator)`.
