<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Telegram;

use Obrazmisli\Agentio\Telegram\Transcription\Transcriber;
use Obrazmisli\Agentio\Telegram\Transcription\TranscriptionException;
use Throwable;

/**
 * Answers a message of the developer (php artisan agentio:telegram assist <key>, one at a time): transcribes a
 * voice message, asks the assistant, makes the changes it decided on (YouTrack, merges of pull requests) and
 * replies with what was done.
 * When the assistant is unavailable, a reply to a question is still recorded in its issue as a comment.
 */
final readonly class Responder
{
    public function __construct(
        private Conversation $conversation,
        private Bot $bot,
        private Transcriber $transcriber,
        private Assistant $assistant,
        private ActionRunner $actions,
        private Messenger $messenger,
    ) {}

    /**
     * Answer the message $key and every other one waiting, oldest first, under the assistant's lock: a process
     * started for a later message that gets the lock first answers the earlier one first. Returns false when the
     * message is not waiting (answered already).
     */
    public function answer(string $key): bool
    {
        if ($this->conversation->inbox($key) === null) {
            return false;
        }

        $lock = fopen($this->conversation->assistantLock(), 'c');

        try {
            if ($lock !== false) {
                flock($lock, LOCK_EX);
            }

            foreach ($this->conversation->inboxKeys() as $waiting) {
                $message = $this->conversation->inbox($waiting);

                try {
                    if ($message !== null) {
                        $this->respond($message);
                    }
                } finally {
                    $this->conversation->forgetInbox($waiting);
                }
            }
        } finally {
            if ($lock !== false) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }

        return true;
    }

    private function respond(IncomingMessage $message): void
    {
        $text = $message->text;
        $heard = null;

        if ($message->isVoice()) {
            try {
                $heard = $this->transcriber->transcribe($this->bot->download((string) $message->voiceFileId), 'voice.oga');
            } catch (TranscriptionException|TelegramException $exception) {
                $this->messenger->send('🎙 Не смог распознать голосовое: '.$exception->getMessage().'. Напишите, пожалуйста, текстом.', 'error', replyTo: $message->messageId);

                return;
            }

            $text = trim($heard."\n".$text);
        }

        if ($text === '') {
            $this->messenger->send('🎙 В голосовом не удалось разобрать слов. Повторите, пожалуйста.', 'error', replyTo: $message->messageId);

            return;
        }

        $about = $message->replyToId === null ? null : $this->conversation->about($message->replyToId);
        $history = $this->conversation->history();
        $this->conversation->addHistory('developer', $text, $about['issue'] ?? null);

        try {
            $decision = $this->assistant->decide($this->assistant->prompt($message, $text, $about, $history));
        } catch (Throwable $exception) {
            $decision = self::fallback($about, $exception);
        }

        $result = $this->actions->run($decision, $message, $text);
        $reply = trim(implode("\n\n", array_filter([
            $heard === null ? null : '🎙 «'.TelegramText::limit($heard, 300).'»',
            $decision->reply,
            implode("\n", $result['lines']),
        ])));

        $this->messenger->send($reply !== '' ? $reply : 'Принял.', $result['kind'] ?? 'answer', $result['issues'][0] ?? $about['issue'] ?? null, replyTo: $message->messageId);
    }

    /**
     * Without the assistant: a reply to a question is recorded in its issue (the Stage stays, it is not clear the
     * answer is complete); anything else waits for the developer to try again.
     *
     * @param  array{kind: string, issue: string|null, stage: string|null}|null  $about
     */
    private static function fallback(?array $about, Throwable $exception): Decision
    {
        $reason = TelegramText::limit($exception->getMessage(), 300);

        if ($about !== null && $about['kind'] === 'question' && $about['issue'] !== null) {
            return new Decision(
                "⚠️ Ассистент сейчас недоступен ({$reason}), поэтому записал ваш ответ в {$about['issue']} как есть. Когда ответите на все вопросы, верните задачу в работу в YouTrack или напишите мне «вернуть в работу» реплаем на вопросы.",
                [['type' => DecisionAction::Answer, 'issue' => $about['issue'], 'comment' => '', 'resume' => false, 'summary' => '', 'description' => '', 'confirm' => false]],
            );
        }

        return new Decision("⚠️ Ассистент сейчас недоступен ({$reason}). Сообщение сохранено в истории — повторите его позже.");
    }
}
