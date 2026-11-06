<?php

declare(strict_types=1);

namespace ItsStarter\Sms\Data;

final readonly class SendResult
{
    /**
     * @param  list<string>  $invalidNumbers
     */
    public function __construct(
        public string $id,
        public string $status,
        public int $recipients,
        public int $segments,
        public int $creditsCharged,
        public int $creditsRemaining,
        public array $invalidNumbers = [],
        public int $duplicatesSkipped = 0,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $invalid = $data['invalid_numbers'] ?? [];

        return new self(
            id: (string) ($data['id'] ?? ''),
            status: (string) ($data['status'] ?? ''),
            recipients: (int) ($data['recipients'] ?? 0),
            segments: (int) ($data['segments'] ?? 0),
            creditsCharged: (int) ($data['credits_charged'] ?? 0),
            creditsRemaining: (int) ($data['credits_remaining'] ?? 0),
            invalidNumbers: is_array($invalid) ? array_values(array_map('strval', $invalid)) : [],
            duplicatesSkipped: (int) ($data['duplicates_skipped'] ?? 0),
        );
    }
}
