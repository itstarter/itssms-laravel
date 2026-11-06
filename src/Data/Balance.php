<?php

declare(strict_types=1);

namespace ItsStarter\Sms\Data;

use DateTimeImmutable;
use Exception;

final readonly class Balance
{
    public function __construct(
        public int $credits,
        public ?string $plan = null,
        public ?DateTimeImmutable $planRenewsAt = null,
        public bool $lowBalance = false,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $renews = null;

        if (isset($data['plan_renews_at']) && is_string($data['plan_renews_at']) && $data['plan_renews_at'] !== '') {
            try {
                $renews = new DateTimeImmutable($data['plan_renews_at']);
            } catch (Exception) {
                $renews = null;
            }
        }

        return new self(
            credits: (int) ($data['credits'] ?? 0),
            plan: isset($data['plan']) ? (string) $data['plan'] : null,
            planRenewsAt: $renews,
            lowBalance: (bool) ($data['low_balance'] ?? false),
        );
    }
}
