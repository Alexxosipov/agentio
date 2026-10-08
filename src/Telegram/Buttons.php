<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Telegram;

use Obrazmisli\Agentio\Process\AgentCommentKind;
use Obrazmisli\Agentio\Process\AgentComments;
use Obrazmisli\Agentio\YouTrack\Mcp\Tools;
use Obrazmisli\Agentio\YouTrack\State;
use Obrazmisli\Agentio\YouTrack\YouTrackException;
use Throwable;

/**
 * The buttons under the questions of the agents (Reports::questionButtons()), pressed by the developer (on the
 * queue, see HandleTelegramUpdate):
 *
 * - accept: «Принимаю рекомендации» is recorded as the answer and the issue goes back to work, as a reply with
 *   these words would (ActionRunner::answer()). Only while the issue waits in Blocked for these questions: the
 *   button of an older round of questions, or of an issue already answered, does nothing;
 * - reply: the bot asks for the answer in a message the developer replies to, tied to the issue like the
 *   questions themselves, so the assistant reads the reply as the answer.
 */
final readonly class Buttons
{
    public const string ACCEPT = 'accept';

    public const string REPLY = 'reply';

    /** The answer the accept button records: the agents read it as the agreement with every recommendation. */
    public const string ACCEPTED = 'Принимаю рекомендации';

    public function __construct(
        private BotSettings $settings,
        private Bot $bot,
        private Tools $tools,
        private ActionRunner $actions,
        private Messenger $messenger,
        private Conversation $conversation,
    ) {}

    public function press(ButtonPress $press): void
    {
        if (! $this->settings->isConfigured()) {
            return;
        }

        if ($this->settings->chatId === null || $press->chatId !== $this->settings->chatId) {
            $this->answer($press, 'Этот бот работает только с чатом разработчика.');

            return;
        }

        $issue = $press->issue();

        match ($issue === null ? null : $press->action()) {
            self::ACCEPT => $this->accept($press, (string) $issue),
            self::REPLY => $this->reply($press, (string) $issue),
            default => $this->answer($press, 'Эта кнопка больше не работает.'),
        };
    }

    private function accept(ButtonPress $press, string $id): void
    {
        try {
            $issue = $this->tools->issueWithLinks($id);

            if ($issue === null || ! $issue->hasState(State::Blocked)) {
                $this->answer($press, $issue === null ? "Задача {$id} не найдена." : "{$id} уже не ждёт ответа (сейчас ".($issue->state() ?? 'без статуса').').', alert: true);
                $this->removeButtons($press);

                return;
            }

            $questions = AgentComments::fromComments($this->tools->comments($id))->last(AgentCommentKind::Blocked);

            // The questions were asked again after this message: its button would accept answers nobody has read.
            if ($questions?->createdAt !== null && $press->messageDate > 0 && $questions->createdAt->getTimestamp() > $press->messageDate) {
                $this->answer($press, "По {$id} агенты задали новые вопросы: ответьте на свежее сообщение с ними.", alert: true);
                $this->removeButtons($press);

                return;
            }

            $text = self::ACCEPTED.' (кнопка «Принять рекомендации» под вопросами).';
            $message = new IncomingMessage(0, $press->messageId, $press->chatId, $text, $press->from, replyToId: $press->messageId, replyToBot: true, date: time());
            [$line] = $this->actions->answer($id, $text, true, $message, $text);
        } catch (YouTrackException $exception) {
            $this->answer($press, 'Не удалось записать в YouTrack: '.TelegramText::limit($exception->getMessage(), 150), alert: true);

            return;
        }

        $this->answer($press, 'Рекомендации приняты.');
        $this->removeButtons($press);
        $this->conversation->addHistory('developer', self::ACCEPTED, $id);
        $this->messenger->send($line, 'answer', $id, replyTo: $press->messageId);
    }

    private function reply(ButtonPress $press, string $id): void
    {
        $about = $this->conversation->about($press->messageId);
        $this->answer($press);
        $this->messenger->send(
            "✍️ Напишите ответ на вопросы по {$id} ответом (reply) на это сообщение — текстом или голосовым: «В1: б; В2: а», «принимаю рекомендации, кроме В2: …» или своими словами.",
            'question',
            $id,
            $about['stage'] ?? null,
            replyTo: $press->messageId,
            markup: ['force_reply' => true, 'input_field_placeholder' => 'Ответ на вопросы '.$id],
        );
    }

    private function answer(ButtonPress $press, string $text = '', bool $alert = false): void
    {
        try {
            $this->bot->answerButton($press->id, $text, $alert);
        } catch (Throwable) {
            // The spinner on the button stops by itself.
        }
    }

    private function removeButtons(ButtonPress $press): void
    {
        try {
            $this->bot->removeButtons($press->chatId, $press->messageId);
        } catch (Throwable) {
            // Only tidiness: a pressed button does nothing twice.
        }
    }
}
