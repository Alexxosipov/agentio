<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Obrazmisli\Agentio\Agentio;
use Obrazmisli\Agentio\Dashboard\ActivityPresenter;
use Obrazmisli\Agentio\Dashboard\BoardPresenter;
use Obrazmisli\Agentio\Dashboard\EpicPresenter;
use Obrazmisli\Agentio\Dashboard\IssueLogPresenter;
use Obrazmisli\Agentio\Dashboard\PipelinePresenter;
use Obrazmisli\Agentio\Dashboard\ReviewPresenter;
use Obrazmisli\Agentio\Dashboard\SessionsPresenter;
use Obrazmisli\Agentio\Dashboard\StatusPresenter;
use Obrazmisli\Agentio\Dashboard\YouTrackSource;
use Obrazmisli\Agentio\Review\EpicAcceptance;
use Obrazmisli\Agentio\Review\EpicBranch;
use Obrazmisli\Agentio\Review\EpicPause;
use Obrazmisli\Agentio\Review\ReviewException;
use Obrazmisli\Agentio\Settings;
use Obrazmisli\Agentio\Telegram\Messenger;

/**
 * The JSON endpoints of the dashboard: the ones it polls, the acceptance actions on an epic in Review and the pause
 * of an epic (POST, disabled with agentio.ui.actions = false). YouTrack failures never fail a response:
 * every payload that needs YouTrack carries a "youtrack" block with the connection state.
 * The YouTrack token never leaves the server: should a session log or an error message
 * contain it, it is replaced with "[redacted]".
 */
final class ApiController
{
    /** The longest remark accepted when an epic is sent back. */
    public const int MAX_REMARK = 10_000;

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

    public function issueLog(IssueLogPresenter $presenter, string $id): JsonResponse
    {
        return self::json($presenter->present($id));
    }

    public function events(ActivityPresenter $presenter): JsonResponse
    {
        return self::json($presenter->events());
    }

    public function loopLog(ActivityPresenter $presenter): JsonResponse
    {
        return self::json($presenter->loopLog());
    }

    public function review(Request $request, ReviewPresenter $presenter, string $id): JsonResponse
    {
        return self::json($presenter->present($id, self::actionsEnabled() && Agentio::canManage($request)));
    }

    public function diff(Request $request, Settings $settings, string $id): JsonResponse
    {
        $diff = EpicBranch::find($settings, $id)?->diff((string) $request->query('file', ''));

        return $diff === null ? self::json(['message' => 'Ветка эпика не меняет этот файл.'], 404) : self::json($diff);
    }

    public function accept(Request $request, EpicAcceptance $acceptance, YouTrackSource $source, string $id): JsonResponse
    {
        $refused = self::refuseAction($request);

        if ($refused !== null) {
            return $refused;
        }

        try {
            return self::json($acceptance->accept(
                $id,
                removeWorktree: $request->boolean('removeWorktree', true),
                deleteBranch: $request->boolean('deleteBranch'),
                close: $request->boolean('close', true),
            ));
        } catch (ReviewException $exception) {
            return self::failure($exception);
        } finally {
            $source->flush();
        }
    }

    public function rework(Request $request, EpicAcceptance $acceptance, YouTrackSource $source, string $id): JsonResponse
    {
        $refused = self::refuseAction($request);

        if ($refused !== null) {
            return $refused;
        }

        $story = trim((string) $request->input('story', ''));
        $remark = trim((string) $request->input('remark', ''));

        if ($story === '' || $remark === '' || mb_strlen($remark) > self::MAX_REMARK) {
            return self::json(['message' => 'Укажите историю и замечание (до '.self::MAX_REMARK.' символов).'], 422);
        }

        try {
            return self::json($acceptance->rework($id, $story, $remark));
        } catch (ReviewException $exception) {
            return self::failure($exception);
        } finally {
            $source->flush();
        }
    }

    /**
     * Pause the epic: after its current wave of tasks, or with now right away; the developer's bot is told, with
     * the epics that wait for it.
     */
    public function pause(Request $request, EpicPause $pause, Messenger $messenger, YouTrackSource $source, string $id): JsonResponse
    {
        $refused = self::refuseAction($request);

        if ($refused !== null) {
            return $refused;
        }

        $reason = trim((string) $request->input('reason', ''));

        try {
            $result = $pause->pause($id, $request->boolean('now'), $reason === '' ? null : mb_substr($reason, 0, self::MAX_REMARK), 'панель agentio');
            $messenger->send(EpicPause::pausedMessage($result), 'report', $result['epic']);

            return self::json([...$result, 'message' => EpicPause::pausedMessage($result)]);
        } catch (ReviewException $exception) {
            return self::failure($exception);
        } finally {
            $source->flush();
        }
    }

    public function resume(Request $request, EpicPause $pause, Messenger $messenger, YouTrackSource $source, string $id): JsonResponse
    {
        $refused = self::refuseAction($request);

        if ($refused !== null) {
            return $refused;
        }

        try {
            $result = $pause->resume($id, 'панель agentio');
            $messenger->send(EpicPause::resumedMessage($result), 'report', $result['epic']);

            return self::json([...$result, 'message' => EpicPause::resumedMessage($result)]);
        } catch (ReviewException $exception) {
            return self::failure($exception);
        } finally {
            $source->flush();
        }
    }

    private static function actionsEnabled(): bool
    {
        return (bool) config('agentio.ui.actions', true);
    }

    /**
     * The answer to an action that may not run: actions disabled, a user without the manageAgentio gate, or a
     * request without a session (then nothing checks its CSRF token).
     */
    private static function refuseAction(Request $request): ?JsonResponse
    {
        return match (true) {
            ! self::actionsEnabled() => self::json(['message' => 'Действия в панели отключены (AGENTIO_UI_ACTIONS=false).'], 403),
            ! Agentio::canManage($request) => self::json(['message' => 'Нет права управлять эпиками (gate manageAgentio).'], 403),
            ! $request->hasSession() => self::json(['message' => 'Действия требуют сессию с CSRF-токеном: ui.middleware должен запускать сессию (как web).'], 403),
            default => null,
        };
    }

    private static function failure(ReviewException $exception): JsonResponse
    {
        return self::json(['message' => $exception->getMessage(), 'details' => $exception->details], $exception->status);
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
