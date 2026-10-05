<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Telegram;

/**
 * A message the developer sent the bot: text or voice (a voice message or an audio file), and the message it
 * replies to, if any.
 */
final readonly class IncomingMessage
{
    public function __construct(
        public int $updateId,
        public int $messageId,
        public string $chatId,
        public string $text = '',
        public ?string $from = null,
        public ?string $voiceFileId = null,
        public ?int $voiceDuration = null,
        public ?int $replyToId = null,
        public ?string $replyToText = null,
        public bool $replyToBot = false,
        public int $date = 0,
    ) {}

    /**
     * The message of an update, or null for anything else (edits, channel posts, stickers, photos without text).
     *
     * @param  array<array-key, mixed>  $update
     */
    public static function fromUpdate(array $update): ?self
    {
        $message = is_array($update['message'] ?? null) ? $update['message'] : null;
        $chat = is_array($message['chat'] ?? null) ? $message['chat'] : [];

        if ($message === null || ! is_int($update['update_id'] ?? null) || ! is_int($message['message_id'] ?? null) || ! is_scalar($chat['id'] ?? null)) {
            return null;
        }

        $voice = is_array($message['voice'] ?? null) ? $message['voice'] : (is_array($message['audio'] ?? null) ? $message['audio'] : null);
        $text = is_string($message['text'] ?? null) ? $message['text'] : (is_string($message['caption'] ?? null) ? $message['caption'] : '');

        if ($voice === null && trim($text) === '') {
            return null;
        }

        $from = is_array($message['from'] ?? null) ? $message['from'] : [];
        $reply = is_array($message['reply_to_message'] ?? null) ? $message['reply_to_message'] : null;
        $replyFrom = is_array($reply['from'] ?? null) ? $reply['from'] : [];

        return new self(
            updateId: $update['update_id'],
            messageId: $message['message_id'],
            chatId: (string) $chat['id'],
            text: trim($text),
            from: is_string($from['username'] ?? null) ? $from['username'] : (is_string($from['first_name'] ?? null) ? $from['first_name'] : null),
            voiceFileId: is_string($voice['file_id'] ?? null) ? $voice['file_id'] : null,
            voiceDuration: is_int($voice['duration'] ?? null) ? $voice['duration'] : null,
            replyToId: is_int($reply['message_id'] ?? null) ? $reply['message_id'] : null,
            replyToText: is_string($reply['text'] ?? null) ? $reply['text'] : (is_string($reply['caption'] ?? null) ? $reply['caption'] : null),
            replyToBot: ($replyFrom['is_bot'] ?? false) === true,
            date: is_int($message['date'] ?? null) ? $message['date'] : 0,
        );
    }

    /**
     * @param  array<array-key, mixed>  $data  As toArray() returned it
     */
    public static function fromArray(array $data): ?self
    {
        if (! is_int($data['updateId'] ?? null) || ! is_int($data['messageId'] ?? null) || ! is_string($data['chatId'] ?? null)) {
            return null;
        }

        return new self(
            updateId: $data['updateId'],
            messageId: $data['messageId'],
            chatId: $data['chatId'],
            text: is_string($data['text'] ?? null) ? $data['text'] : '',
            from: is_string($data['from'] ?? null) ? $data['from'] : null,
            voiceFileId: is_string($data['voiceFileId'] ?? null) ? $data['voiceFileId'] : null,
            voiceDuration: is_int($data['voiceDuration'] ?? null) ? $data['voiceDuration'] : null,
            replyToId: is_int($data['replyToId'] ?? null) ? $data['replyToId'] : null,
            replyToText: is_string($data['replyToText'] ?? null) ? $data['replyToText'] : null,
            replyToBot: ($data['replyToBot'] ?? false) === true,
            date: is_int($data['date'] ?? null) ? $data['date'] : 0,
        );
    }

    public function isVoice(): bool
    {
        return $this->voiceFileId !== null;
    }

    /**
     * The bot command the message starts with ("/start"), without the @botname suffix, or null.
     */
    public function command(): ?string
    {
        return preg_match('#^/([a-z_]+)(?:@\w+)?(?:\s|$)#i', $this->text, $match) === 1 ? strtolower($match[1]) : null;
    }

    /**
     * The text after the command ("/start abc" → "abc").
     */
    public function commandArgument(): string
    {
        return trim((string) preg_replace('#^/\S+\s*#', '', $this->text));
    }

    /**
     * @return array{updateId: int, messageId: int, chatId: string, text: string, from: string|null, voiceFileId: string|null, voiceDuration: int|null, replyToId: int|null, replyToText: string|null, replyToBot: bool, date: int}
     */
    public function toArray(): array
    {
        return [
            'updateId' => $this->updateId,
            'messageId' => $this->messageId,
            'chatId' => $this->chatId,
            'text' => $this->text,
            'from' => $this->from,
            'voiceFileId' => $this->voiceFileId,
            'voiceDuration' => $this->voiceDuration,
            'replyToId' => $this->replyToId,
            'replyToText' => $this->replyToText,
            'replyToBot' => $this->replyToBot,
            'date' => $this->date,
        ];
    }
}
