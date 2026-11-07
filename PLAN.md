# itstarter/sms — Laravel package plan (DRAFT, awaiting approval)

Official Laravel client for **ITS SMS**, the SMS gateway by IT Starter (Pvt) Ltd.
The package talks **only to the ITS SMS portal API**. Dialog eSMS lives inside the portal;
the package never holds carrier credentials.

Status: planning. No code until this plan is approved. No public release until the
portal API is live on its final domain.

---

## 1. Decisions taken

| Item | Decision |
| --- | --- |
| Package type | Composer package for Laravel (npm/Node package later, same contract) |
| Composer name | `itstarter/sms` |
| PHP namespace | `ItsStarter\Sms` |
| Repo | `itssms-laravel` under the IT Starter GitHub org (org name to confirm) |
| Talks to | ITS SMS portal API only |
| Publish | Not before the portal API is live; first tag `v0.1.0`, `1.0.0` once the API is stable |
| Licence | MIT |

## 2. Open items

1. ~~GitHub org~~ → `itstarter` (verified 2026-11-06: riyajkm is a member). Repo `itstarter/itssms-laravel`.
2. ~~Portal domain~~ → `sms.itstarter.cc` (decided 2026-11-07). Default base URL `https://sms.itstarter.cc/api/v1`, configurable with `ITSSMS_BASE_URL`.
3. ~~Versions~~ → PHP ^8.3, Laravel 12 and 13 (Laravel 13.40 current; 13 needs PHP 8.3; PHP 8.2 security support ends Dec 2026).
4. ~~Packagist name~~ → `itstarter/sms` is free (verified 2026-11-06).

---

## 3. API contract v1 (shared by package and portal)

This replaces the conflicting parts of the two original specs. The portal must implement
exactly this. Kept as `contract/openapi.yaml` in this repo once approved.

**Auth and headers:** `Authorization: Bearer <apiKey>`, `Accept: application/json`,
`Content-Type: application/json`, `User-Agent: itssms-laravel/<version>`.
Every response carries `X-Request-Id`.

**Naming:** the term is **Sender Name** → field `sender`, endpoint `/senders`
(not `sender_id` / `/sender-ids`).

**Numbers:** the client may send any common format; the package normalises to
`94XXXXXXXXX` (11 digits, Sri Lankan mobiles, `947…`). The portal re-validates and converts
to Dialog's 9-digit form internally.

### 3.1 `POST /sms/send`

Request: `to` (string or array, max 1,000), `message`, `sender` (optional, account default),
`type` (`transactional` default | `promotional`), `reference` (optional), `schedule_at`
(optional ISO 8601, max 7 days ahead).

Header `Idempotency-Key: <uuid>` (required for the package, optional for raw API users).

Response `200`:
```json
{ "id": "cmp_01JB8X2K9Q", "status": "queued", "recipients": 2, "segments": 1,
  "credits_charged": 2, "credits_remaining": 1498,
  "invalid_numbers": [], "duplicates_skipped": 0 }
```
- `credits_charged = segments × billable recipients × (1 transactional | 2 promotional)`.
- Billable = valid, not duplicate. Duplicate guard: same number + same text within
  10 minutes → skipped, status `duplicate`, never billed.

### 3.2 Idempotency (portal must implement)
- Portal stores `(api_key_id, Idempotency-Key)` with a hash of the body for 24 hours.
- Same key + same body → returns the original response, no new send, no new charge.
- Same key + different body → `409 idempotency_conflict`.

### 3.3 `GET /sms/{id}`
```json
{ "id": "cmp_01JB8X2K9Q", "status": "completed", "reference": "INV-1042",
  "messages": [
    { "to": "94771234567", "status": "delivered", "delivered_at": "2026-10-30T10:01:12+05:30" },
    { "to": "94712345678", "status": "failed", "error": "number_unreachable" } ] }
```
Send statuses: `queued`, `scheduled`, `processing`, `completed`, `cancelled`.
Message statuses: `queued`, `submitted`, `delivered`, `failed`, `duplicate`
(only move forward; Dialog reports can arrive out of order).

### 3.4 `GET /balance`
`{ "credits": 1498, "plan": "Startup", "plan_renews_at": "2026-11-27", "low_balance": false }`
Credits only; LKR never appears in the API.

### 3.5 `GET /senders`
`[{ "name": "BillBook", "type": "transactional", "status": "active", "credits": 500 }]`
`credits` is `null` when the sender spends from the main Credit Balance.

### 3.6 Webhooks (portal → client)
Events: `message.submitted`, `message.delivered`, `message.failed`.
```json
{ "event": "message.delivered", "id": "cmp_01JB8X2K9Q", "to": "94771234567",
  "status": "delivered", "reference": "INV-1042", "timestamp": "2026-10-30T10:01:12+05:30" }
```
Headers: `X-ITS-Timestamp: <unix seconds>`, `X-ITS-Signature: sha256=<hex>`,
hex = `HMAC-SHA256(webhookSecret, "<timestamp>.<raw body>")`. Reject older than 300 s.
Portal retries failed deliveries (backoff), so client handlers must be idempotent.

### 3.7 Errors
Body: `{ "error": { "code": "insufficient_credits", "message": "..." } }`

| HTTP | Codes |
| --- | --- |
| 401 | `invalid_api_key`, `api_key_revoked` |
| 402 | `insufficient_credits`, `sender_credits_empty`, `plan_lapsed` |
| 403 | `sender_not_allowed`, `account_suspended`, `scope_missing` |
| 409 | `blackout_period`, `idempotency_conflict` |
| 422 | `invalid_number`, `sender_not_approved`, `message_too_long`, `validation_failed` |
| 429 | `rate_limited` (+ `Retry-After`) |
| 5xx | `server_error` |

### 3.8 Portal-side note (not part of the package)
Dialog `transaction_id` is per Dialog call attempt, never the campaign ID: one campaign may
need several Dialog calls (1,000 numbers each), and a retry needs a new ID (Dialog error 104).

---

## 4. Package design

### 4.1 Usage
```php
use ItsStarter\Sms\Facades\Sms;

// Fluent send
$result = Sms::to(['0771234567', '+94712345678'])
    ->message('Your invoice INV-1042 of LKR 4,500 is due on 30 Oct.')
    ->from('BillBook')          // optional, config default
    ->reference('INV-1042')     // optional
    ->promotional()             // optional, default transactional
    ->at(now()->addHour())      // optional schedule
    ->send();                   // SendResult (array of results if >1,000 numbers)

// Shortcut
Sms::send('0771234567', 'Hello from BillBook');

// Read
Sms::status($result->id);
Sms::balance();
Sms::senders();

// Several API keys side by side (agency use), from config `accounts`
Sms::account('teamcomrade')->to('0771234567')->message('...')->send();

// Helpers, no network
Sms::normalizeNumber('0771234567');   // '94771234567'
Sms::countSegments('Hello');          // Segments{encoding: 'GSM-7', characters: 5, segments: 1, perSegment: 160}
```

### 4.2 Notification channel
```php
public function via($notifiable) { return [ItsSmsChannel::class]; }

public function toItsSms($notifiable): ItsSmsMessage
{
    return ItsSmsMessage::create('Your OTP is 482913')->reference('login-77');
}

// On the model
public function routeNotificationForItsSms($notification) { return $this->phone; }
```

### 4.3 Webhooks
- Config `webhook.path` (e.g. `itssms/webhook`); when set, the package registers a POST
  route protected by `VerifyItsSmsSignature` middleware (raw body, timing-safe compare,
  300 s tolerance).
- Valid calls dispatch Laravel events: `MessageSubmitted`, `MessageDelivered`, `MessageFailed`
  (all carry a typed `WebhookEvent`). The app listens; no logic in the package.
- Also usable manually: `Sms::verifyWebhook($request)`.

### 4.4 Testing support
```php
Sms::fake();
// ... app code ...
Sms::assertSent(fn (PendingSms $sms) => $sms->hasRecipient('94771234567'));
Sms::assertSentTo('0771234567');
Sms::assertSentCount(2);
Sms::assertNothingSent();
```

### 4.5 Config (`config/itssms.php`)
```php
'api_key'     => env('ITSSMS_API_KEY'),
'sender'      => env('ITSSMS_SENDER'),
'base_url'    => env('ITSSMS_BASE_URL', 'https://sms.itstarter.cc/api/v1'),
'timeout'     => 15,
'max_retries' => 2,
'webhook'     => ['secret' => env('ITSSMS_WEBHOOK_SECRET'), 'path' => null, 'tolerance' => 300],
'accounts'    => [ /* 'teamcomrade' => ['api_key' => ..., 'sender' => ...] */ ],
```

### 4.6 Behaviour rules (from the original spec, kept)
1. Numbers: accept `0771234567`, `771234567`, `94771234567`, `+94771234567`, spaces, dashes.
   Normalise to `94XXXXXXXXX`, mobiles only. Reject bad numbers locally with
   `ItsSmsValidationError` listing them. De-duplicate within a request.
2. Segments: GSM-7 160 / 153; UCS-2 (Sinhala, Tamil, emoji, curly quotes) 70 / 67;
   extended chars `^ { } \ [ ] ~ | €` count 2. Log a warning above 6 segments.
3. Sender: max 11 chars, letters, digits, space, `.`, `-`, `_`.
4. Batching: >1,000 numbers → sequential requests of 1,000.
5. Retries: network errors, 429, 5xx only; backoff 0.5 s, 1 s, 2 s; honour `Retry-After`.
   Never retry other 4xx. One `Idempotency-Key` per batch, reused across its retries.
6. Errors (all extend `ItsSmsException` with `status`, `code`, `requestId`):
   `AuthException` 401, `CreditException` 402, `PermissionException` 403,
   `ConflictException` 409, `ValidationException` 422 + local, `RateLimitException` 429,
   `ServerException` 5xx, `NetworkException` timeout / no connection.
7. Security: the API key never appears in logs or exception messages; missing key throws
   on first use (not at boot, so `config:cache` and tests without a key still work).
8. No global state between accounts.

### 4.7 Dependencies
- Runtime: `illuminate/support`, `illuminate/http` (Laravel's HTTP client, gives retries,
  timeouts and `Http::fake()` for our own tests). Nothing else.
- Dev: `orchestra/testbench`, `pestphp/pest`, `larastan/larastan`, `laravel/pint`.
- Not using `spatie/laravel-package-tools`: a plain service provider is a few lines and
  keeps runtime dependencies at zero beyond Laravel.

### 4.8 Folder layout
```
itssms-laravel/
  src/
    SmsServiceProvider.php
    Facades/Sms.php
    SmsManager.php          accounts, fake swap
    Client.php              HTTP: auth, retries, idempotency, error mapping
    PendingSms.php          fluent builder
    Support/PhoneNumber.php normalise
    Support/Segments.php    segment counter
    Data/                   SendResult, SendStatus, MessageStatus, Balance, Sender, WebhookEvent
    Exceptions/             one class per error
    Notifications/          ItsSmsChannel, ItsSmsMessage
    Webhooks/               VerifyItsSmsSignature, WebhookController, Events/
    Testing/SmsFake.php
  config/itssms.php
  contract/openapi.yaml     the API contract (section 3), shared with the portal
  tests/                    Pest + Testbench, Http::fake
  README.md  CHANGELOG.md  LICENSE  composer.json  phpstan.neon  pint.json
  .github/workflows/tests.yml
```

---

## 5. Tests (must pass before release)
- Number normalisation: every accepted format; landlines and foreign numbers rejected.
- Segments: 160 / 161 GSM-7, 70 / 71 Sinhala, extended chars, emoji forces UCS-2.
- Send: URL, headers, body; numbers normalised and de-duplicated; 1,001 numbers → 2 requests.
- Retries: 503 then 200 with the same `Idempotency-Key`; 422 not retried; `Retry-After` honoured.
- Each HTTP error → right exception with `code` and `requestId`; timeout → `NetworkException`.
- Webhook: valid passes and fires the event; tampered body, wrong secret, old timestamp → 401.
- Notification channel sends via the client; `Sms::fake()` assertions work.
- Multiple accounts use their own keys.
- API key never appears in any exception message or log line.
- Coverage ≥ 90%, PHPStan level 8 (Larastan), Pint clean.
- CI matrix: PHP 8.3 / 8.4 × Laravel 12 / 13.

## 6. Build steps (after approval)
1. Scaffold: `composer.json`, service provider, config, Pest + Testbench, CI. Git init.
2. Write `contract/openapi.yaml` from section 3.
3. Support helpers: phone number, segments (TDD).
4. HTTP client: auth, errors, retries, idempotency, batching (TDD with `Http::fake`).
5. Fluent builder, facade, manager with accounts.
6. Notification channel.
7. Webhook middleware, route, events.
8. Fake and assertions.
9. README (install, quick start, notifications, webhooks, errors, segments, security, support).
10. Full check: tests, coverage, PHPStan, Pint; independent review.
11. Push to GitHub (your OK). Packagist submission only when the portal API is live (your OK).

## 7. Out of scope for v0.1
- npm/Node package (later, same contract).
- WordPress/WooCommerce plugin, plain-PHP SDK.
- WhatsApp and email channels (keep the HTTP client channel-neutral so they can be added).
- Artisan commands (e.g. `itssms:send`, `itssms:balance`) — easy add-on if wanted.
