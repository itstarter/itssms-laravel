<?php

declare(strict_types=1);

namespace ItsStarter\Sms\Testing;

use Closure;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Str;
use ItsStarter\Sms\Data\SendResult;
use ItsStarter\Sms\Exceptions\ConfigurationException;
use ItsStarter\Sms\PendingSms;
use ItsStarter\Sms\SmsManager;
use ItsStarter\Sms\Support\Segments;
use PHPUnit\Framework\Assert;

/**
 * Drop-in replacement for SmsManager in tests. The real Client still runs (so bad numbers,
 * empty messages and bad senders fail exactly as in production), but its HTTP layer answers
 * locally and every accepted send is recorded for assertions.
 */
class SmsFake extends SmsManager
{
    /** @var list<PendingSms> */
    private array $sent = [];

    public function sendOn(?string $account, PendingSms $sms): SendResult|array
    {
        $result = parent::sendOn($account, $sms);

        $this->sent[] = clone $sms;

        return $result;
    }

    /**
     * Recorded sends, optionally filtered by a callback receiving the PendingSms.
     *
     * @return list<PendingSms>
     */
    public function sent(?callable $callback = null): array
    {
        return array_values(array_filter(
            $this->sent,
            fn (PendingSms $sms) => $callback === null || (bool) $callback($sms),
        ));
    }

    public function assertSent(?callable $callback = null): void
    {
        Assert::assertNotEmpty($this->sent($callback), 'The expected SMS was not sent.');
    }

    public function assertNotSent(?callable $callback = null): void
    {
        Assert::assertEmpty($this->sent($callback), 'An unexpected SMS was sent.');
    }

    /**
     * Assert a message went to this number, written in any format.
     */
    public function assertSentTo(string $number, ?callable $callback = null): void
    {
        Assert::assertNotEmpty(
            $this->sent(fn (PendingSms $sms) => $sms->hasRecipient($number) && ($callback === null || $callback($sms))),
            "No SMS was sent to {$number}.",
        );
    }

    public function assertSentCount(int $count): void
    {
        Assert::assertCount($count, $this->sent, 'Expected '.$count.' SMS send(s), but '.count($this->sent).' were made.');
    }

    public function assertNothingSent(): void
    {
        Assert::assertEmpty($this->sent, 'SMS were sent unexpectedly ('.count($this->sent).').');
    }

    /**
     * Any account name works in tests, configured or not; the key is always a dummy.
     */
    protected function settings(?string $name): array
    {
        try {
            $settings = parent::settings($name);
        } catch (ConfigurationException) {
            $settings = parent::settings(null);
            $settings['sender'] = null;
        }

        $settings['api_key'] = 'fake_key';

        return $settings;
    }

    protected function httpFactory(): Factory
    {
        return (new Factory)->fake(Closure::fromCallable($this->respond(...)));
    }

    private function respond(Request $request): mixed
    {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $data = $request->data();

        if ($request->method() === 'POST' && str_ends_with($path, '/sms/send')) {
            $recipients = is_array($data['to'] ?? null) ? count($data['to']) : 1;
            $segments = Segments::count((string) ($data['message'] ?? ''))->segments;
            $charged = $segments * $recipients * (($data['type'] ?? '') === 'promotional' ? 2 : 1);

            return Factory::response([
                'id' => 'cmp_FAKE'.strtoupper(Str::random(10)),
                'status' => isset($data['schedule_at']) ? 'scheduled' : 'queued',
                'recipients' => $recipients,
                'segments' => $segments,
                'credits_charged' => $charged,
                'credits_remaining' => 10000 - $charged,
                'invalid_numbers' => [],
                'duplicates_skipped' => 0,
            ]);
        }

        if (str_ends_with($path, '/balance')) {
            return Factory::response(['credits' => 10000, 'plan' => 'Fake', 'plan_renews_at' => null, 'low_balance' => false]);
        }

        if (str_ends_with($path, '/senders')) {
            return Factory::response([['name' => 'FAKE', 'type' => 'transactional', 'status' => 'active', 'credits' => null]]);
        }

        return Factory::response(['id' => basename($path), 'status' => 'completed', 'reference' => null, 'messages' => []]);
    }
}
