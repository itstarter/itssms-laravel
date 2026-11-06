<?php

declare(strict_types=1);

namespace ItsStarter\Sms\Data;

use DateTimeImmutable;
use Exception;

final readonly class WebhookEvent
{
    /**
     * @param  string  $type  Event name, e.g. "message.delivered".
     * @param  array<string, mixed>  $payload  The full decoded body.
     */
    public function __construct(
        public string $type,
        public string $id,
        public string $to,
        public string $status,
        public ?string $reference = null,
        public ?DateTimeImmutable $timestamp = null,
        public array $payload = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $timestamp = null;

        if (isset($data['timestamp']) && is_string($data['timestamp']) && $data['timestamp'] !== '') {
            try {
                $timestamp = new DateTimeImmutable($data['timestamp']);
            } catch (Exception) {
                $timestamp = null;
            }
        }

        return new self(
            type: (string) ($data['event'] ?? ''),
            id: (string) ($data['id'] ?? ''),
            to: (string) ($data['to'] ?? ''),
            status: (string) ($data['status'] ?? ''),
            reference: isset($data['reference']) ? (string) $data['reference'] : null,
            timestamp: $timestamp,
            payload: $data,
        );
    }
}
