# Тесты интеграций на Saloon

Saloon отправляет запросы своим Guzzle-sender: `Http::fake()` и `Http::preventStrayRequests()` Laravel их **не** перехватывают. Используй фейки Saloon.

## Запрет реальных запросов

В `tests/Pest.php` (или `TestCase`) проекта — один раз:

```php
use Saloon\Config;
use Saloon\Http\Faking\MockClient;

Config::preventStrayRequests();                 // запрос без MockClient → StrayRequestException

uses()->afterEach(fn () => MockClient::destroyGlobal())->in(__DIR__);
```

## MockClient

```php
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('creates a payment', function () {
    $mock = MockClient::global([
        CreatePayment::class => MockResponse::make(['id' => 'pay_1', 'status' => 'pending'], 201),
        GetPayment::class => MockResponse::make(['id' => 'pay_1', 'status' => 'paid']),
        'api.acme.test/v1/refunds*' => MockResponse::make([], 204),   // по URL (wildcard)
    ]);

    $payment = app(CreatePaymentAction::class)->handle($order);

    expect($payment->status)->toBe('pending');
    $mock->assertSent(CreatePayment::class);
    $mock->assertSent(fn (Request $request, Response $response): bool => $request instanceof CreatePayment
        && $request->headers()->get('Idempotency-Key') === $order->payment_key
        && $request->body()->get('amount') === 1990);
    $mock->assertSentCount(1, CreatePayment::class);
    $mock->assertNotSent(RefundPayment::class);
});
```

- `MockClient::global()` создаёт глобальный мок один раз: повторный вызов в том же тесте вернёт **прежний** — для другого набора ответов сначала `MockClient::destroyGlobal()`.
- Ключи: класс запроса, класс коннектора, URL-шаблон (`*`); без ключа — последовательность ответов по порядку (`new MockClient([MockResponse::make(...), MockResponse::make(...)])`).
- Ответ-замыкание: `fn (PendingRequest $pendingRequest): MockResponse => …` — ответ по содержимому запроса.
- Только для одного коннектора: `$connector->withMockClient($mock)` (коннектор из контейнера — `$this->app->instance(AcmeConnector::class, $connector)`).
- Laravel-плагин (если установлен): `Saloon::fake([...])`, `Saloon::assertSent(...)`, `Saloon::assertNothingSent()`.

## Сценарии

```php
MockResponse::make(['error' => 'invalid amount'], 422);                     // 4xx — ошибка данных, без ретрая
MockResponse::make([], 503);                                                // 5xx — ретраи, затем исключение
MockResponse::make([], 429, ['Retry-After' => '30']);                       // лимит
MockResponse::make('<html>oops</html>', 200, ['Content-Type' => 'text/html']); // неожиданный формат
MockResponse::make()->throw(fn (PendingRequest $pendingRequest) => new FatalRequestException(new ConnectException('timeout', $pendingRequest->createPsrRequest()), $pendingRequest)); // соединение
```

Для каждого: что видит пользователь, что в БД, что с джобом (`release`, `fail`), что в логе. Ретраи в тестах не ждут: `Saloon\Config::sleepUsing(fn () => null)` или `$tries = 1` у коннектора в тесте.

## Фикстуры

Записанные ответы реального API (для сложных JSON): `MockResponse::fixture('acme/get-order')` — файл `tests/Fixtures/Saloon/acme/get-order.json`, путь задаётся `MockConfig::setFixturePath(base_path('tests/Fixtures/Saloon'))`. Без файла Saloon попытается записать реальный ответ — в автономной сессии сети нет: создай фикстуру вручную по документации (`MockConfig::throwOnMissingFixtures()` — падать, а не ходить в сеть). Секреты в фикстурах не хранят.

## Что проверять

- Каждый Request-класс: метод, endpoint, query, тело, заголовки (ключ идемпотентности), DTO из типового ответа.
- Коннектор: base URL и токен из `config/services.php`, таймауты заданы, ретраи только на временных ошибках (`assertSentCount(3)` на 503, `1` на 422).
- Поведение продукта при недоступности сервиса.
- Вебхуки — отдельно: подпись, дубль, порядок (external-integrations.md).
