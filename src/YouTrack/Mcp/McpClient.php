<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\YouTrack\Mcp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use JsonException;
use Obrazmisli\Agentio\YouTrack\YouTrackException;
use Throwable;

/**
 * A client of the YouTrack MCP server (`<url>/mcp`, streamable HTTP transport): JSON-RPC requests with the
 * permanent token as a bearer token. The session is initialized on the first call; a server that answers
 * with an `Mcp-Session-Id` header gets it back on every following request.
 *
 * Tools answer with a text content block that holds JSON (decoded by call()) or plain text (callText()).
 * A tool error (`isError`) becomes a YouTrackException; "… not found" errors have status 404.
 */
final class McpClient
{
    public const string PROTOCOL_VERSION = '2025-06-18';

    private bool $initialized = false;

    private ?string $sessionId = null;

    private int $requestId = 0;

    public function __construct(
        private readonly ?string $url,
        private readonly ?string $token,
        private readonly int $timeout = 30,
        private readonly int $retries = 2,
    ) {}

    /**
     * Whether both the URL and the token are set.
     */
    public function isConfigured(): bool
    {
        return $this->baseUrl() !== '' && (string) $this->token !== '';
    }

    /**
     * The YouTrack instance URL without a trailing slash, e.g. https://example.youtrack.cloud.
     */
    public function baseUrl(): string
    {
        return rtrim((string) $this->url, '/');
    }

    /**
     * The MCP endpoint of the instance.
     */
    public function endpoint(): string
    {
        return $this->baseUrl().'/mcp';
    }

    /**
     * Call a tool whose answer is JSON.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<array-key, mixed>
     *
     * @throws YouTrackException
     */
    public function call(string $tool, array $arguments = []): array
    {
        $text = $this->callText($tool, $arguments);

        try {
            $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ['text' => $text];
        }

        return is_array($decoded) ? $decoded : ['value' => $decoded];
    }

    /**
     * Call a tool and return the text of its answer.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @throws YouTrackException
     */
    public function callText(string $tool, array $arguments = []): string
    {
        $this->initialize();

        $result = $this->request('tools/call', ['name' => $tool, 'arguments' => (object) $arguments]);
        $text = '';

        foreach (is_array($result['content'] ?? null) ? $result['content'] : [] as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            }
        }

        if (($result['isError'] ?? false) === true) {
            $message = trim((string) preg_replace('/^Error:\s*/i', '', $text));

            throw new YouTrackException(
                sprintf('YouTrack MCP %s failed: %s', $tool, $message === '' ? 'unknown error' : $message),
                preg_match('/\bnot found\b/i', $message) === 1 ? 404 : 400,
            );
        }

        return $text;
    }

    /**
     * Every page of a tool with "offset" / "limit" arguments whose answer is a list, or an object with the list
     * under $pageKey and a "hasNextPage" flag.
     *
     * @param  array<string, mixed>  $arguments
     * @return list<array<array-key, mixed>>
     *
     * @throws YouTrackException
     */
    public function paginate(string $tool, array $arguments, int $limit, ?string $pageKey = null): array
    {
        $items = [];

        for ($offset = 0; ; $offset += $limit) {
            $answer = $this->call($tool, [...$arguments, 'offset' => $offset, 'limit' => $limit]);
            $page = $pageKey === null ? $answer : ($answer[$pageKey] ?? []);
            $page = is_array($page) ? array_values(array_filter($page, is_array(...))) : [];
            array_push($items, ...$page);

            $more = $pageKey === null ? count($page) >= $limit : ($answer['hasNextPage'] ?? false) === true;

            if (! $more || $page === []) {
                return $items;
            }
        }
    }

    /**
     * @throws YouTrackException
     */
    private function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        if (! $this->isConfigured()) {
            throw YouTrackException::notConfigured();
        }

        $this->request('initialize', [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'obrazmisli/agentio', 'version' => '1'],
        ]);
        $this->notify('notifications/initialized');
        $this->initialized = true;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<array-key, mixed>
     *
     * @throws YouTrackException
     */
    private function request(string $method, array $params): array
    {
        $id = ++$this->requestId;
        $response = $this->send(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => (object) $params], $method);
        $session = $response->header('Mcp-Session-Id');

        if ($session !== '') {
            $this->sessionId = $session;
        }

        $message = $this->message($response, $id);

        if (is_array($message['error'] ?? null)) {
            $error = $message['error'];

            throw new YouTrackException(sprintf('YouTrack MCP %s failed: %s', $method, is_string($error['message'] ?? null) ? $error['message'] : 'JSON-RPC error'));
        }

        return is_array($message['result'] ?? null) ? $message['result'] : [];
    }

    /**
     * @throws YouTrackException
     */
    private function notify(string $method): void
    {
        $this->send(['jsonrpc' => '2.0', 'method' => $method], $method);
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws YouTrackException
     */
    private function send(array $payload, string $method): Response
    {
        try {
            $response = $this->http()->post($this->endpoint(), $payload);
        } catch (ConnectionException $exception) {
            throw YouTrackException::connectionFailed('MCP', $method, $exception);
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw new YouTrackException(sprintf('YouTrack MCP %s failed with HTTP %d: the token was rejected (is it a valid permanent token?)', $method, $response->status()), $response->status());
        }

        if ($response->failed()) {
            throw YouTrackException::requestFailed('MCP', $method, $response->status(), mb_substr(trim($response->body()), 0, 500));
        }

        return $response;
    }

    /**
     * The JSON-RPC message answering the request: the JSON body, or the matching "data:" event of a
     * text/event-stream body.
     *
     * @return array<array-key, mixed>
     *
     * @throws YouTrackException
     */
    private function message(Response $response, int $id): array
    {
        $candidates = [];

        if (str_contains(mb_strtolower($response->header('Content-Type')), 'text/event-stream')) {
            foreach (preg_split('/\R/', $response->body()) ?: [] as $line) {
                if (str_starts_with($line, 'data:')) {
                    $candidates[] = json_decode(trim(mb_substr($line, 5)), true);
                }
            }
        } else {
            $candidates[] = $response->json();
        }

        foreach ($candidates as $candidate) {
            if (is_array($candidate) && ($candidate['id'] ?? null) === $id) {
                return $candidate;
            }
        }

        throw new YouTrackException('YouTrack MCP returned no answer to the request: is '.$this->endpoint().' the MCP endpoint of a YouTrack instance?');
    }

    private function http(): PendingRequest
    {
        $headers = ['Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => self::PROTOCOL_VERSION];

        if ($this->sessionId !== null) {
            $headers['Mcp-Session-Id'] = $this->sessionId;
        }

        return Http::withToken((string) $this->token)
            ->withHeaders($headers)
            ->asJson()
            ->timeout($this->timeout)
            ->connectTimeout(min($this->timeout, 10))
            ->retry(
                $this->retries + 1,
                fn (int $attempt): int => $attempt * 500,
                fn (Throwable $exception): bool => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && ($exception->response->serverError() || $exception->response->status() === 429)),
                throw: false,
            );
    }
}
