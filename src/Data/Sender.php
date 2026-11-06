<?php

declare(strict_types=1);

namespace ItsStarter\Sms\Data;

final readonly class Sender
{
    /**
     * @param  int|null  $credits  Null when the sender spends from the main Credit Balance.
     */
    public function __construct(
        public string $name,
        public string $type,
        public string $status,
        public ?int $credits = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) ($data['name'] ?? ''),
            type: (string) ($data['type'] ?? ''),
            status: (string) ($data['status'] ?? ''),
            credits: isset($data['credits']) ? (int) $data['credits'] : null,
        );
    }
}
