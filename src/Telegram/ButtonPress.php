<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Telegram;

/**
 * The developer pressed a button under a message of the bot (a callback_query of the Bot API): which button
 * (its callback data, "<action>:<issue id>") under which message.
 */
final readonly class ButtonPress
{
    public function __construct(
        public string $id,
        public string $chatId,
        public int $messageId,
        public string $data,
        public ?string $from = null,
        public int $messageDate = 0,
    ) {}

    /**
     * The press of an update, or null for anything else.
     *
     * @param  array<array-key, mixed>  $update
     */
    public static function fromUpdate(array $update): ?self
    {
        $query = is_array($update['callback_query'] ?? null) ? $update['callback_query'] : null;
        $message = is_array($query['message'] ?? null) ? $query['message'] : [];
        $chat = is_array($message['chat'] ?? null) ? $message['chat'] : [];

        if ($query === null || ! is_string($query['id'] ?? null) || ! is_int($message['message_id'] ?? null) || ! is_scalar($chat['id'] ?? null)) {
            return null;
        }

        $from = is_array($query['from'] ?? null) ? $query['from'] : [];

        return new self(
            id: $query['id'],
            chatId: (string) $chat['id'],
            messageId: $message['message_id'],
            data: is_string($query['data'] ?? null) ? $query['data'] : '',
            from: is_string($from['username'] ?? null) ? $from['username'] : (is_string($from['first_name'] ?? null) ? $from['first_name'] : null),
            messageDate: is_int($message['date'] ?? null) ? $message['date'] : 0,
        );
    }

    /**
     * The action of the button ("accept"), or null when the data is not "<action>:<issue id>".
     */
    public function action(): ?string
    {
        return preg_match('/^([a-z]+):[A-Za-z][A-Za-z0-9_]*-\d+$/', $this->data, $match) === 1 ? $match[1] : null;
    }

    public function issue(): ?string
    {
        return preg_match('/^[a-z]+:([A-Za-z][A-Za-z0-9_]*-\d+)$/', $this->data, $match) === 1 ? strtoupper($match[1]) : null;
    }
}
