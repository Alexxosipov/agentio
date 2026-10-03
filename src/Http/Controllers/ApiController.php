<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Obrazmisli\Agentio\Dashboard\ActivityPresenter;
use Obrazmisli\Agentio\Dashboard\BoardPresenter;
use Obrazmisli\Agentio\Dashboard\EpicPresenter;
use Obrazmisli\Agentio\Dashboard\PipelinePresenter;
use Obrazmisli\Agentio\Dashboard\SessionsPresenter;
use Obrazmisli\Agentio\Dashboard\StatusPresenter;

/**
 * The JSON endpoints the dashboard polls. YouTrack failures never fail a response:
 * every payload that needs YouTrack carries a "youtrack" block with the connection state.
 * The YouTrack token never leaves the server: should a session log or an error message
 * contain it, it is replaced with "[redacted]".
 */
final class ApiController
{
    /** Shorter "tokens" (test values, placeholders) are not worth redacting and would mangle ordinary text. */
    private const int MIN_SECRET_LENGTH = 8;

    public function status(StatusPresenter $presenter): JsonResponse
    {
        return self::json($presenter->present());
    }

    public function sessions(SessionsPresenter $presenter): JsonResponse
    {
        return self::json($presenter->present());
    }

    public function pipeline(PipelinePresenter $presenter): JsonResponse
    {
        return self::json($presenter->present());
    }

    public function epic(EpicPresenter $presenter, string $id): JsonResponse
    {
        $epic = $presenter->present($id);

        return $epic === null ? self::json(['message' => "Задача {$id} не найдена."], 404) : self::json($epic);
    }

    public function board(BoardPresenter $presenter): JsonResponse
    {
        return self::json($presenter->present());
    }

    public function events(ActivityPresenter $presenter): JsonResponse
    {
        return self::json($presenter->events());
    }

    public function loopLog(ActivityPresenter $presenter): JsonResponse
    {
        return self::json($presenter->loopLog());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function json(array $data, int $status = 200): JsonResponse
    {
        $response = new JsonResponse($data, $status, ['Cache-Control' => 'no-store'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $token = config('agentio.youtrack.token');

        if (is_string($token) && strlen($token) >= self::MIN_SECRET_LENGTH) {
            $response->setContent(str_replace($token, '[redacted]', (string) $response->getContent()));
        }

        return $response;
    }
}
