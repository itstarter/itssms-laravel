<?php

declare(strict_types=1);

namespace ItsStarter\Sms\Data;

final readonly class SendStatus
{
    /**
     * @param  list<MessageStatus>  $messages
     */
    public function __construct(
        public string $id,
        public string $status,
        public ?string $reference = null,
        public array $messages = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $messages = [];

        foreach (is_array($data['messages'] ?? null) ? $data['messages'] : [] as $message) {
            if (is_array($message)) {
                /** @var array<string, mixed> $message */
                $messages[] = MessageStatus::fromArray($message);
            }
        }

        return new self(
            id: (string) ($data['id'] ?? ''),
            status: (string) ($data['status'] ?? ''),
            reference: isset($data['reference']) ? (string) $data['reference'] : null,
            messages: $messages,
        );
    }
}
