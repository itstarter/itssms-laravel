<?php

declare(strict_types=1);

namespace ItsStarter\Sms\Data;

use DateTimeImmutable;
use Exception;

final readonly class MessageStatus
{
    public function __construct(
        public string $to,
        public string $status,
        public ?DateTimeImmutable $deliveredAt = null,
        public ?string $error = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            to: (string) ($data['to'] ?? ''),
            status: (string) ($data['status'] ?? ''),
            deliveredAt: self::date($data['delivered_at'] ?? null),
            error: isset($data['error']) ? (string) $data['error'] : null,
        );
    }

    private static function date(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }
}
