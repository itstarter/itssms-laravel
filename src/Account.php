<?php

declare(strict_types=1);

namespace ItsStarter\Sms;

use ItsStarter\Sms\Data\Balance;
use ItsStarter\Sms\Data\Sender;
use ItsStarter\Sms\Data\SendResult;
use ItsStarter\Sms\Data\SendStatus;

/**
 * One configured account (API key). Returned by SmsManager::account().
 */
final class Account
{
    public function __construct(
        private readonly SmsManager $manager,
        private readonly ?string $name = null,
    ) {}

    /**
     * @param  string|list<string>  $to
     */
    public function to(string|array $to): PendingSms
    {
        return (new PendingSms($this->manager, $this->name))->to($to);
    }

    /**
     * @param  string|list<string>  $to
     * @return SendResult|list<SendResult>
     */
    public function send(string|array $to, string $message): SendResult|array
    {
        return $this->to($to)->message($message)->send();
    }

    public function status(string $id): SendStatus
    {
        return $this->manager->statusOn($this->name, $id);
    }

    public function balance(): Balance
    {
        return $this->manager->balanceOn($this->name);
    }

    /**
     * @return list<Sender>
     */
    public function senders(): array
    {
        return $this->manager->sendersOn($this->name);
    }
}
