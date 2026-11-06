<?php

declare(strict_types=1);

namespace ItsStarter\Sms\Facades;

use Illuminate\Support\Facades\Facade;
use ItsStarter\Sms\SmsManager;
use ItsStarter\Sms\Testing\SmsFake;
use LogicException;

/**
 * @method static \ItsStarter\Sms\Account account(?string $name = null)
 * @method static \ItsStarter\Sms\PendingSms to(string|list<string> $to)
 * @method static \ItsStarter\Sms\Data\SendResult|list<\ItsStarter\Sms\Data\SendResult> send(string|list<string> $to, string $message)
 * @method static \ItsStarter\Sms\Data\SendStatus status(string $id)
 * @method static \ItsStarter\Sms\Data\Balance balance()
 * @method static list<\ItsStarter\Sms\Data\Sender> senders()
 * @method static string|null normalizeNumber(string $number)
 * @method static \ItsStarter\Sms\Support\Segments countSegments(string $message)
 * @method static \ItsStarter\Sms\Data\WebhookEvent verifyWebhook(\Illuminate\Http\Request $request)
 * @method static void assertSent(?callable $callback = null)
 * @method static void assertNotSent(?callable $callback = null)
 * @method static void assertSentTo(string $number, ?callable $callback = null)
 * @method static void assertSentCount(int $count)
 * @method static void assertNothingSent()
 *
 * @see SmsManager
 */
class Sms extends Facade
{
    /**
     * Replace the manager with a fake that records sends instead of calling the API.
     */
    public static function fake(): SmsFake
    {
        $app = static::getFacadeApplication() ?? throw new LogicException('The Laravel application is not available.');
        $fake = new SmsFake($app);

        static::swap($fake);
        $app->instance(SmsManager::class, $fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return 'itssms';
    }
}
