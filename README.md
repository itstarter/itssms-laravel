# ITS SMS for Laravel

The official Laravel client for **ITS SMS**, the SMS gateway by IT Starter (Pvt) Ltd.

> **Pre-release.** This package is built against the ITS SMS API contract v1
> ([`contract/openapi.yaml`](contract/openapi.yaml)). The ITS SMS portal API is not live yet, so
> the package is not published on Packagist and the default base URL is a placeholder. Do not use
> it in production until the first tagged release.

## Why this package

- **One call to send**: `Sms::send('0771234567', 'Hello')`.
- **Sri Lankan numbers handled for you**: `0771234567`, `771234567`, `+94 77 123 4567` all become `94771234567`. Bad numbers are rejected locally, before any request or charge.
- **Safe retries**: network errors, `429` and `5xx` are retried with backoff, and automatic retries within one call reuse the same `Idempotency-Key`. To make your own retries (queue jobs, trying again) safe, pass `->idempotencyKey('order-1042')`; queued notifications get one automatically.
- **Typed errors**: one exception class per failure, each with the HTTP status, an error code and the request id.
- **Laravel native**: facade, notification channel, signed webhooks as events, and `Sms::fake()` for tests.
- **Credit-aware**: count segments before you send.

The package talks only to the ITS SMS portal API. It never holds carrier credentials.

## Requirements

PHP 8.3+ and Laravel 12 or 13.

## Installation

```bash
composer require itstarter/sms
```

The service provider and the `Sms` facade are discovered automatically. Publish the config file if you want to edit it:

```bash
php artisan vendor:publish --tag=itssms-config
```

## Configuration

Add to `.env`:

```env
ITSSMS_API_KEY=your-api-key
ITSSMS_SENDER=BillBook
ITSSMS_WEBHOOK_SECRET=your-webhook-secret
# Only if your portal runs on another domain:
# ITSSMS_BASE_URL=https://sms.itstarter.cc/api/v1
```

`config/itssms.php`:

```php
return [
    'api_key' => env('ITSSMS_API_KEY'),
    'sender' => env('ITSSMS_SENDER'),            // default Sender Name
    'base_url' => env('ITSSMS_BASE_URL', 'https://sms.itstarter.cc/api/v1'),
    'timeout' => 15,                              // seconds
    'max_retries' => 2,                           // network errors, 429 and 5xx only
    'webhook' => [
        'secret' => env('ITSSMS_WEBHOOK_SECRET'),
        'path' => null,                           // e.g. 'itssms/webhook' to enable the route
        'tolerance' => 300,                       // seconds
    ],
    'accounts' => [
        // 'teamcomrade' => ['api_key' => env('TEAMCOMRADE_ITSSMS_API_KEY'), 'sender' => 'TeamComrade'],
    ],
];
```

A missing API key does not break boot or `config:cache`. It throws a `ConfigurationException` the first time you send.

## Quick start

```php
use ItsStarter\Sms\Facades\Sms;

$result = Sms::send('0771234567', 'Your invoice INV-1042 of LKR 4,500 is due on 30 Oct.');

$result->id;               // 'cmp_01JB8X2K9Q'
$result->status;           // 'queued'
$result->creditsCharged;   // 1
$result->creditsRemaining; // 1499
```

`send()` returns a `SendResult`. The same call accepts an array of numbers.

## Fluent options

```php
$result = Sms::to(['0771234567', '+94712345678'])
    ->message('Your invoice INV-1042 of LKR 4,500 is due on 30 Oct.')
    ->from('BillBook')          // Sender Name; defaults to ITSSMS_SENDER
    ->reference('INV-1042')     // your own reference, returned in status and webhooks
    ->promotional()             // default is transactional
    ->at(now()->addHour())      // schedule, up to 7 days ahead
    ->send();
```

| Method | Meaning |
| --- | --- |
| `to(string\|array $to)` | One or more recipients. Can be called more than once. |
| `message(string $text)` | The message text. |
| `from(?string $sender)` | Sender Name: 1 to 11 characters, letters, digits, space, `.`, `-`, `_`. |
| `reference(?string $ref)` | Free text reference. |
| `promotional()` / `transactional()` | Message type. Transactional is the default. |
| `at(DateTimeInterface\|string $when)` | Schedule time (ISO 8601 string or any date object). |
| `send()` | Send it. Nothing leaves your app before this call. |

## Many numbers, scheduling, promotional

```php
// Duplicates are removed locally. More than 1,000 numbers are split into
// sequential requests of 1,000, and send() then returns a list of SendResult.
$numbers = User::whereNotNull('phone')->pluck('phone')->all();

$results = Sms::to($numbers)
    ->message('Weekend sale: 20% off at BillBook.')
    ->promotional()
    ->at(now()->addDay())
    ->send();

$results = is_array($results) ? $results : [$results];

foreach ($results as $batch) {
    logger()->info("Campaign {$batch->id}: {$batch->recipients} recipients, {$batch->creditsCharged} credits.");
}
```

A promotional message costs 2 credits per segment per recipient. See [Segments and credits](#segments-and-credits).

## Status and balance

```php
$status = Sms::status($result->id);
$status->status;               // queued | scheduled | processing | completed | cancelled
foreach ($status->messages as $message) {
    $message->to;              // '94771234567'
    $message->status;          // queued | submitted | delivered | failed | duplicate
    $message->deliveredAt;     // ?DateTimeImmutable
    $message->error;           // e.g. 'number_unreachable'
}

$balance = Sms::balance();
$balance->credits;             // 1498
$balance->plan;                // 'Startup'
$balance->planRenewsAt;        // ?DateTimeImmutable
$balance->lowBalance;          // bool

foreach (Sms::senders() as $sender) {
    $sender->name;             // 'BillBook'
    $sender->type;             // 'transactional'
    $sender->status;           // 'active'
    $sender->credits;          // ?int, null when it spends from the main balance
}
```

Credits are the only unit the API reports. Rupee amounts never appear.

## Multiple accounts

Agencies and multi-brand apps can keep several API keys side by side under `accounts` in `config/itssms.php`:

```php
'accounts' => [
    'teamcomrade' => ['api_key' => env('TEAMCOMRADE_ITSSMS_API_KEY'), 'sender' => 'TeamComrade'],
],
```

```php
Sms::account('teamcomrade')->to('0771234567')->message('Hello from TeamComrade')->send();
Sms::account('teamcomrade')->send('0771234567', 'Same thing, shorter');
Sms::account('teamcomrade')->balance();
```

Every account has its own key and its own client; nothing is shared. A named account takes only its own `api_key` and `sender`; it falls back to the top-level `base_url`, `timeout` and `max_retries` unless it sets its own. An unknown account name throws a `ConfigurationException`.

## Notifications

Use ITS SMS as a Laravel notification channel.

```php
use ItsStarter\Sms\Notifications\ItsSmsChannel;
use ItsStarter\Sms\Notifications\ItsSmsMessage;

class LoginOtp extends Notification
{
    public function __construct(private string $code) {}

    public function via($notifiable): array
    {
        return [ItsSmsChannel::class];   // or ['itssms']
    }

    public function toItsSms($notifiable): ItsSmsMessage
    {
        return ItsSmsMessage::create("Your OTP is {$this->code}")->reference('login-77');
    }
}
```

`toItsSms()` may also return a plain string. `ItsSmsMessage` supports `from()`, `reference()`, `promotional()`, `transactional()`, `at()` and `account('name')`.

Tell Laravel where to send, on the model:

```php
public function routeNotificationForItsSms($notification): ?string
{
    return $this->phone;
}
```

If that method returns an empty value, the notification is skipped silently. For a one-off number: `Notification::route('itssms', '0771234567')->notify(new LoginOtp('482913'))`.

## Webhooks

ITS SMS calls your app when a message is submitted, delivered or failed.

1. Set the path and the secret:

   ```env
   ITSSMS_WEBHOOK_SECRET=your-webhook-secret
   ```

   ```php
   // config/itssms.php
   'webhook' => ['secret' => env('ITSSMS_WEBHOOK_SECRET'), 'path' => 'itssms/webhook', 'tolerance' => 300],
   ```

   The package then registers `POST /itssms/webhook` (route name `itssms.webhook`). It has no session or CSRF middleware. If `path` is `null`, no route exists.

2. Give ITS SMS the full URL of that route and the same secret.

Every call is checked before your code runs: `X-ITS-Timestamp` must be within `tolerance` seconds, and `X-ITS-Signature` must equal `sha256=` plus the HMAC-SHA256 of `"<timestamp>.<raw body>"` with your secret, compared in constant time. A bad signature, an old timestamp or a missing secret returns `401`. Unknown event types are answered with `200` and ignored.

A valid call dispatches one of these Laravel events, each carrying a readonly `WebhookEvent` in `$event->event`:

| Event | Webhook |
| --- | --- |
| `ItsStarter\Sms\Webhooks\Events\MessageSubmitted` | `message.submitted` |
| `ItsStarter\Sms\Webhooks\Events\MessageDelivered` | `message.delivered` |
| `ItsStarter\Sms\Webhooks\Events\MessageFailed` | `message.failed` |

`WebhookEvent` has `type`, `id`, `to`, `status`, `reference`, `timestamp` (`?DateTimeImmutable`) and `payload` (the full decoded body).

```php
use Illuminate\Contracts\Queue\ShouldQueue;
use ItsStarter\Sms\Webhooks\Events\MessageDelivered;

class MarkInvoiceReminderDelivered implements ShouldQueue
{
    public function handle(MessageDelivered $e): void
    {
        // The portal retries failed deliveries, so make this idempotent.
        Reminder::where('reference', $e->event->reference)
            ->whereNull('delivered_at')
            ->update(['delivered_at' => $e->event->timestamp]);
    }
}
```

Laravel discovers listeners in `app/Listeners` automatically. Queue the heavy work (`ShouldQueue`) so your endpoint answers fast.

To verify a request yourself, for example in your own route:

```php
use ItsStarter\Sms\Exceptions\WebhookException;

try {
    $event = Sms::verifyWebhook($request);   // WebhookEvent
} catch (WebhookException $e) {
    abort(401);
}
```

`verifyWebhook()` throws a `ConfigurationException` when no webhook secret is set.

## Testing

`Sms::fake()` assertions need PHPUnit or Pest (a dev dependency, already present in Laravel apps).

`Sms::fake()` swaps the manager so no request leaves your tests. It still normalises and validates numbers, so a bad number fails in tests exactly as it would in production. It also works for notifications and named accounts, and needs no API key.

```php
use ItsStarter\Sms\Facades\Sms;
use ItsStarter\Sms\PendingSms;

it('texts the customer', function () {
    Sms::fake();

    // ... run your app code ...

    Sms::assertSent(fn (PendingSms $sms) => $sms->hasRecipient('94771234567'));
    Sms::assertSentTo('0771234567');               // any number format
    Sms::assertSentTo('0771234567', fn (PendingSms $sms) => str_contains($sms->getMessage(), 'OTP'));
    Sms::assertSentCount(1);
    Sms::assertNotSent(fn (PendingSms $sms) => $sms->isPromotional());
    // Sms::assertNothingSent();
});
```

A `PendingSms` exposes `getRecipients()` (normalised), `getMessage()`, `getSender()`, `getReference()`, `getType()`, `isPromotional()`, `getScheduleAt()` and `getAccount()`.

## Errors

Every error extends `ItsStarter\Sms\Exceptions\ItsSmsException`, which has `$status` (HTTP status, `0` for local errors), `$errorCode` (a stable string such as `insufficient_credits`) and `$requestId` (quote it to support).

| Exception | When | Typical `errorCode` |
| --- | --- | --- |
| `AuthException` | 401 | `invalid_api_key`, `api_key_revoked` |
| `CreditException` | 402 | `insufficient_credits`, `sender_credits_empty`, `plan_lapsed` |
| `PermissionException` | 403 | `sender_not_allowed`, `account_suspended`, `scope_missing` |
| `ConflictException` | 409 | `blackout_period`, `idempotency_conflict` |
| `ValidationException` | 422, or rejected locally before sending | `invalid_number`, `sender_not_approved`, `message_too_long`, `validation_failed` |
| `RateLimitException` | 429 | `rate_limited` (has `$retryAfter` seconds) |
| `ServerException` | 5xx | `server_error` |
| `NetworkException` | timeout or no connection | |
| `ConfigurationException` | no API key, unknown account, no webhook secret | `missing_api_key`, `unknown_account`, `missing_webhook_secret` |
| `WebhookException` | untrusted or unreadable webhook | `invalid_signature`, `stale_timestamp`, `invalid_payload` |

```php
use ItsStarter\Sms\Exceptions\CreditException;
use ItsStarter\Sms\Exceptions\ItsSmsException;
use ItsStarter\Sms\Exceptions\ValidationException;

try {
    Sms::send($phone, $text);
} catch (ValidationException $e) {
    // $e->invalidNumbers lists numbers rejected locally
    return back()->withErrors(['phone' => $e->getMessage()]);
} catch (CreditException $e) {
    // Out of credits: tell the team, do not retry.
    Notification::route('mail', 'ops@example.com')->notify(new OutOfSmsCredits($e->errorCode, $e->requestId));
    report($e);
} catch (ItsSmsException $e) {
    report($e);   // $e->status, $e->errorCode, $e->requestId
}
```

If a send to more than 1,000 numbers fails part way, the exception carries the batches that already went out:

```php
try {
    Sms::to($numbers)->message($text)->idempotencyKey('campaign-7')->send();
} catch (ItsSmsException $e) {
    $e->sentResults; // list<SendResult> accepted before the failure (empty if the first batch failed)
}
```

Only network errors, `429` and `5xx` are retried (backoff 0.5 s, 1 s, 2 s, honouring `Retry-After`). Other `4xx` errors are never retried.

## Segments and credits

Long messages are split into segments, and each segment costs credits.

| Encoding | Used when | One message | Per segment when split |
| --- | --- | --- | --- |
| GSM-7 | English and basic symbols | 160 characters | 153 |
| UCS-2 | Sinhala, Tamil, emoji, curly quotes | 70 characters | 67 |

The characters `^ { } \ [ ] ~ | €` count as 2 in GSM-7. One Sinhala, Tamil or emoji character switches the whole message to UCS-2.

```
credits = segments x billable recipients x (1 transactional | 2 promotional)
```

Billable recipients are valid, non-duplicate numbers. The same number with the same text within 10 minutes is skipped and not billed.

Check before you send; no network call is made:

```php
$count = Sms::countSegments('Hello');
$count->encoding;    // 'GSM-7'
$count->characters;  // 5
$count->segments;    // 1
$count->perSegment;  // 160

Sms::normalizeNumber('0771234567');   // '94771234567', or null if not a Sri Lankan mobile
```

A warning is logged when a message spans more than 6 segments.

## Security

- Keep the API key and webhook secret **on the server only**. Never put them in JavaScript, mobile apps or committed files; use `.env`.
- The API key never appears in exception messages or logs.
- Webhooks are verified with an HMAC signature, a timestamp window and a timing-safe comparison. Without a configured secret, every webhook is rejected.
- Rotate the key from the ITS SMS portal if it may have leaked.

## Support

Email [info@itstarter.cc](mailto:info@itstarter.cc) or call +94 77 393 4001. Include the `requestId` from the exception when you report a problem.

## License

MIT. See [LICENSE](LICENSE).
