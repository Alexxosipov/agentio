<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Install;

/**
 * One step of the YouTrack project setup: what was found, created or extended.
 */
final readonly class SetupAction
{
    /**
     * @param  string  $subject  field, bundle, tag, saved search or article
     */
    public function __construct(
        public string $subject,
        public string $name,
        public SetupStatus $status,
        public string $detail = '',
    ) {}

    /**
     * @return array{subject: string, name: string, status: string, detail: string}
     */
    public function toArray(): array
    {
        return ['subject' => $this->subject, 'name' => $this->name, 'status' => $this->status->value, 'detail' => $this->detail];
    }
}
