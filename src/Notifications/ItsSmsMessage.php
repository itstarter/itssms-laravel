<?php

declare(strict_types=1);

namespace ItsStarter\Sms\Notifications;

use DateTimeInterface;

/**
 * What toItsSms() returns when a plain string is not enough.
 */
final class ItsSmsMessage
{
    private ?string $sender = null;

    private ?string $reference = null;

    private string $type = 'transactional';

    private DateTimeInterface|string|null $scheduleAt = null;

    private ?string $account = null;

    private ?string $idempotencyKey = null;

    public function __construct(private string $content = '') {}

    public static function create(string $content = ''): self
    {
        return new self($content);
    }

    public function content(string $content): self
    {
        $this->content = $content;

        return $this;
    }

    public function from(?string $sender): self
    {
        $this->sender = $sender;

        return $this;
    }

    public function reference(?string $reference): self
    {
        $this->reference = $reference;

        return $this;
    }

    public function promotional(): self
    {
        $this->type = 'promotional';

        return $this;
    }

    public function transactional(): self
    {
        $this->type = 'transactional';

        return $this;
    }

    public function at(DateTimeInterface|string|null $when): self
    {
        $this->scheduleAt = $when;

        return $this;
    }

    /** Caller-supplied idempotency key; the channel derives one from the notification when omitted. */
    public function idempotencyKey(string $key): self
    {
        $this->idempotencyKey = $key;

        return $this;
    }

    /** Send through a named account from config `accounts`. */
    public function account(?string $account): self
    {
        $this->account = $account;

        return $this;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getSender(): ?string
    {
        return $this->sender;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getScheduleAt(): DateTimeInterface|string|null
    {
        return $this->scheduleAt;
    }

    public function getIdempotencyKey(): ?string
    {
        return $this->idempotencyKey;
    }

    public function getAccount(): ?string
    {
        return $this->account;
    }
}
