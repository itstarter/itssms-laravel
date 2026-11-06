<?php

declare(strict_types=1);

namespace ItsStarter\Sms;

use DateTimeInterface;
use ItsStarter\Sms\Data\SendResult;
use ItsStarter\Sms\Support\PhoneNumber;

/**
 * Fluent message builder. Nothing leaves the app until send() is called.
 */
final class PendingSms
{
    /** @var list<string> */
    private array $recipients = [];

    private string $message = '';

    private ?string $sender = null;

    private ?string $reference = null;

    private string $type = 'transactional';

    private DateTimeInterface|string|null $scheduleAt = null;

    private ?string $idempotencyKey = null;

    public function __construct(
        private readonly SmsManager $manager,
        private readonly ?string $account = null,
    ) {}

    /**
     * Add one or more recipients, in any common Sri Lankan format.
     *
     * @param  string|list<string>  $to
     */
    public function to(string|array $to): self
    {
        foreach (is_array($to) ? $to : [$to] as $number) {
            $this->recipients[] = (string) $number;
        }

        return $this;
    }

    public function message(string $message): self
    {
        $this->message = $message;

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

    /**
     * Make your own retries safe: the same key is never sent or billed twice. See Client::send().
     */
    public function idempotencyKey(string $key): self
    {
        $this->idempotencyKey = $key;

        return $this;
    }

    /**
     * @return SendResult|list<SendResult> A list when more than 1,000 numbers were split into batches.
     */
    public function send(): SendResult|array
    {
        return $this->manager->sendOn($this->account, $this);
    }

    /**
     * True when the number (any format) is among the valid recipients.
     */
    public function hasRecipient(string $number): bool
    {
        $normalized = PhoneNumber::normalize($number);

        return $normalized !== null && in_array($normalized, $this->getRecipients(), true);
    }

    /**
     * Valid recipients, normalised to 94XXXXXXXXX and de-duplicated.
     *
     * @return list<string>
     */
    public function getRecipients(): array
    {
        $numbers = [];

        foreach ($this->recipients as $number) {
            $normalized = PhoneNumber::normalize($number);

            if ($normalized !== null) {
                $numbers[$normalized] = $normalized;
            }
        }

        return array_values($numbers);
    }

    /**
     * Recipients exactly as given, valid or not.
     *
     * @return list<string>
     */
    public function getRawRecipients(): array
    {
        return $this->recipients;
    }

    public function getMessage(): string
    {
        return $this->message;
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

    public function isPromotional(): bool
    {
        return $this->type === 'promotional';
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
